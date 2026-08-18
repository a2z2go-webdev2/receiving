<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $plainTextToken = $request->bearerToken();
        if (! is_string($plainTextToken)
            || preg_match('/^rcv_([A-Za-z0-9]{16})\.([A-Za-z0-9_-]{43})$/D', $plainTextToken, $matches) !== 1) {
            return $this->unauthorized();
        }

        $apiKey = ApiKey::query()->with('user')->where('public_id', $matches[1])->first();
        $candidateHash = hash('sha256', $matches[2]);
        if (! $apiKey
            || ! hash_equals($apiKey->token_hash, $candidateHash)
            || ! $apiKey->isUsable()
            || ! $apiKey->permits($ability)
            || ! $apiKey->user->isActive()
            || ! $apiKey->user->can(Permission::ViewUploads->value)) {
            return $this->unauthorized();
        }

        if ($apiKey->last_used_at === null || $apiKey->last_used_at->isBefore(now()->subMinutes(5))) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->setUserResolver(fn () => $apiKey->user);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'A valid API key is required.'], 401, [
            'WWW-Authenticate' => 'Bearer',
        ]);
    }
}
