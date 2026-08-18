<?php

namespace App\Http\Controllers\Settings;

use App\Features\Receiving\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateApiKeyRequest;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $this->render($request);
    }

    private function render(Request $request, ?string $newApiKey = null): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/api-keys', [
            'apiKeys' => $user->apiKeys()
                ->whereNull('revoked_at')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (ApiKey $key): array => [
                    'id' => $key->getKey(),
                    'name' => $key->name,
                    'prefix' => "rcv_{$key->public_id}",
                    'last_used_at' => $key->last_used_at?->toISOString(),
                    'expires_at' => $key->expires_at?->toISOString(),
                    'created_at' => $key->created_at->toISOString(),
                ]),
            'newApiKey' => $newApiKey,
            'endpoints' => [
                'serial' => route('api.v1.corrected-data.by-serial'),
                'poNumber' => route('api.v1.corrected-data.by-po-number'),
            ],
        ]);
    }

    public function store(
        CreateApiKeyRequest $request,
        ActivityLogger $activity,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $publicId = Str::random(16);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresIn = $request->string('expires_in_days')->toString();
        $key = $user->apiKeys()->create([
            'name' => $request->string('name')->trim()->toString(),
            'public_id' => $publicId,
            'token_hash' => hash('sha256', $secret),
            'abilities' => [ApiKey::ABILITY_READ_CORRECTED_DATA],
            'expires_at' => $expiresIn === 'never' ? null : now()->addDays((int) $expiresIn),
        ]);

        $activity->record('api', 'api_key_created', 'success', "API key {$key->name} was created.", $user, null, $request);

        return $this->render($request, "rcv_{$publicId}.{$secret}");
    }

    public function destroy(
        Request $request,
        ApiKey $apiKey,
        ActivityLogger $activity,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User && $apiKey->user_id === $user->getKey(), 404);

        if ($apiKey->revoked_at === null) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
            $activity->record('api', 'api_key_revoked', 'success', "API key {$apiKey->name} was revoked.", $user, null, $request);
        }

        return back()->with('status', 'API key revoked.');
    }
}
