<?php

namespace App\Support;

use App\Models\AuthAuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;
use Throwable;

class AuthAudit
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function record(string $event, array $attributes = []): void
    {
        try {
            $request = $attributes['request'] ?? request();
            $request = $request instanceof Request ? $request : null;
            $guard = $attributes['guard'] ?? config('fortify.guard', config('auth.defaults.guard'));

            AuthAuditLog::query()->create([
                'actor_id' => self::userId($attributes['actor'] ?? null),
                'target_user_id' => self::userId($attributes['target'] ?? null),
                'event' => $event,
                'login_identifier' => $attributes['login_identifier']
                    ?? $request?->input(Fortify::username()),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'guard' => $guard,
                'provider' => $attributes['provider'] ?? config("auth.guards.$guard.provider"),
                'success' => (bool) ($attributes['success'] ?? true),
                'failure_reason_code' => $attributes['failure_reason_code'] ?? null,
                'mfa_required' => (bool) ($attributes['mfa_required'] ?? false),
                'mfa_passed' => (bool) ($attributes['mfa_passed'] ?? false),
                'session_id_hash' => self::sessionIdHash($request),
                'token_id' => $attributes['token_id'] ?? null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function userId(mixed $user): ?int
    {
        if ($user instanceof Authenticatable) {
            $key = $user->getAuthIdentifier();

            return is_numeric($key) ? (int) $key : null;
        }

        return is_numeric($user) ? (int) $user : null;
    }

    private static function sessionIdHash(?Request $request): ?string
    {
        if (! $request?->hasSession()) {
            return null;
        }

        return hash('sha256', $request->session()->getId());
    }
}
