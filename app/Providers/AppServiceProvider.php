<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Features\Receiving\Contracts\DocumentExtractor;
use App\Features\Receiving\Contracts\FileScanner;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\ClamAvFileScanner;
use App\Features\Receiving\Services\CloudmersiveFileScanner;
use App\Features\Receiving\Services\GeminiDocumentExtractor;
use App\Features\Receiving\Services\TestingCleanFileScanner;
use App\Features\Receiving\Services\TestingDocumentExtractor;
use App\Features\Receiving\Services\UploadOtpGrant;
use App\Models\ApiKey;
use App\Models\UploadType;
use App\Models\User;
use App\Support\AuthAudit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed as LoginFailed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FileScanner::class, function ($app): FileScanner {
            if ($app->environment('testing', 'local')) {
                return $app->make(TestingCleanFileScanner::class);
            }

            return match (config('receiving.scanner.driver')) {
                'cloudmersive' => $app->make(CloudmersiveFileScanner::class),
                'clamav' => $app->make(ClamAvFileScanner::class),
                default => throw new \RuntimeException('A fail-closed malware scanner driver must be configured.'),
            };
        });

        $this->app->bind(DocumentExtractor::class, function ($app): DocumentExtractor {
            if ($app->environment('testing')) {
                return $app->make(TestingDocumentExtractor::class);
            }

            return $app->make(GeminiDocumentExtractor::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthAuditEvents();
        $this->configureApiRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureApiRateLimiting(): void
    {
        RateLimiter::for('api-auth', fn (Request $request): Limit => Limit::perMinute(60)->by(
            (string) $request->ip(),
        ));

        RateLimiter::for('api-keys', function (Request $request): Limit {
            $apiKey = $request->attributes->get('api_key');

            return Limit::perMinute(120)->by(
                $apiKey instanceof ApiKey ? $apiKey->public_id : (string) $request->ip(),
            );
        });
    }

    /**
     * Record authentication-sensitive events without storing secrets.
     */
    protected function configureAuthAuditEvents(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            AuthAudit::record('login_success', [
                'actor' => $event->user,
                'target' => $event->user,
                'guard' => $event->guard,
                'success' => true,
            ]);

            if ($event->user instanceof User && $event->user->can(Permission::AccessAdmin->value)) {
                app(ActivityLogger::class)->record(
                    'auth',
                    'admin_logged_in',
                    'success',
                    "Administrator {$event->user->email} logged in.",
                    $event->user,
                    null,
                    request(),
                );
            }
        });

        Event::listen(LoginFailed::class, function (LoginFailed $event): void {
            $request = request();
            $identifier = $event->credentials[Fortify::username()]
                ?? $event->credentials['email']
                ?? null;
            $target = $event->user ?? (
                is_string($identifier)
                    ? User::query()->where(Fortify::username(), $identifier)->first()
                    : null
            );

            AuthAudit::record('login_failure', [
                'target' => $target ?? $request->attributes->get('auth.failure_target_user_id'),
                'login_identifier' => $identifier,
                'guard' => $event->guard,
                'success' => false,
                'failure_reason_code' => $request->attributes->get(
                    'auth.failure_reason_code',
                    $target instanceof User && ! $target->isActive() ? 'account_not_active' : 'invalid_credentials',
                ),
            ]);
        });

        Event::listen(Lockout::class, fn (Lockout $event) => AuthAudit::record('login_lockout', [
            'request' => $event->request,
            'login_identifier' => $event->request->input(Fortify::username()),
            'success' => false,
            'failure_reason_code' => 'rate_limited',
        ]));

        Event::listen(Logout::class, function (Logout $event): void {
            AuthAudit::record('logout', [
                'actor' => $event->user,
                'target' => $event->user,
                'guard' => $event->guard,
                'success' => true,
            ]);

            if (! $event->user instanceof User) {
                return;
            }

            $activity = app(ActivityLogger::class);
            if ($event->user->can(Permission::AccessAdmin->value)) {
                $activity->record(
                    'auth',
                    'admin_logged_out',
                    'success',
                    "Administrator {$event->user->email} logged out.",
                    $event->user,
                    null,
                    request(),
                );
            }

            $uploadTypeIds = app(UploadOtpGrant::class)->activeUploadTypeIds(request());
            foreach (UploadType::query()->whereKey($uploadTypeIds)->get() as $uploadType) {
                $activity->record(
                    'auth',
                    'upload_access_logged_out',
                    'success',
                    "{$event->user->email} logged out of the {$uploadType->name} upload page.",
                    $event->user,
                    null,
                    request(),
                );
            }
        });

        Event::listen(Registered::class, fn (Registered $event) => AuthAudit::record('registration_completed', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
        ]));

        Event::listen(PasswordResetLinkSent::class, fn (PasswordResetLinkSent $event) => AuthAudit::record('password_reset_requested', [
            'target' => $event->user,
            'login_identifier' => $event->user->getEmailForPasswordReset(),
            'success' => true,
        ]));

        Event::listen(PasswordReset::class, fn (PasswordReset $event) => AuthAudit::record('password_reset_completed', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
        ]));

        Event::listen(Verified::class, fn (Verified $event) => AuthAudit::record('email_verified', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
        ]));

        Event::listen(TwoFactorAuthenticationChallenged::class, fn (TwoFactorAuthenticationChallenged $event) => AuthAudit::record('mfa_challenge_started', [
            'target' => $event->user,
            'success' => true,
            'mfa_required' => true,
        ]));

        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, fn (ValidTwoFactorAuthenticationCodeProvided $event) => AuthAudit::record('mfa_challenge_passed', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
            'mfa_required' => true,
            'mfa_passed' => true,
        ]));

        Event::listen(TwoFactorAuthenticationFailed::class, fn (TwoFactorAuthenticationFailed $event) => AuthAudit::record('mfa_challenge_failed', [
            'target' => $event->user,
            'success' => false,
            'failure_reason_code' => 'mfa_failed',
            'mfa_required' => true,
            'mfa_passed' => false,
        ]));

        Event::listen(TwoFactorAuthenticationEnabled::class, fn (TwoFactorAuthenticationEnabled $event) => AuthAudit::record('mfa_enabled', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
        ]));

        Event::listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $event) => AuthAudit::record('mfa_confirmed', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
            'mfa_passed' => true,
        ]));

        Event::listen(TwoFactorAuthenticationDisabled::class, fn (TwoFactorAuthenticationDisabled $event) => AuthAudit::record('mfa_disabled', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
        ]));

        Event::listen(RecoveryCodesGenerated::class, fn (RecoveryCodesGenerated $event) => AuthAudit::record('mfa_recovery_codes_generated', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
        ]));

        Event::listen(RecoveryCodeReplaced::class, fn (RecoveryCodeReplaced $event) => AuthAudit::record('mfa_recovery_code_used', [
            'actor' => $event->user,
            'target' => $event->user,
            'success' => true,
            'mfa_required' => true,
            'mfa_passed' => true,
        ]));

    }
}
