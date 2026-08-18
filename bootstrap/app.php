<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\DenyUploaderOnlyAccountSettings;
use App\Http\Middleware\EnsureAdminOtpVerified;
use App\Http\Middleware\EnsureDriverOtpVerified;
use App\Http\Middleware\EnsureUploadOtpVerified;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureWarehouseOtpVerified;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfUnauthorized;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'active' => EnsureUserIsActive::class,
            'upload.otp' => EnsureUploadOtpVerified::class,
            'admin.otp' => EnsureAdminOtpVerified::class,
            'warehouse.otp' => EnsureWarehouseOtpVerified::class,
            'driver.otp' => EnsureDriverOtpVerified::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'starter.permission' => RedirectIfUnauthorized::class,
            'deny.uploader.settings' => DenyUploaderOnlyAccountSettings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return response()->json([
                    'message' => 'Your login session expired. Sign in again.',
                    'code' => 'SESSION_EXPIRED',
                ], 401);
            }

            $redirect = redirect()->guest(route('login'));
            if ($request->hasCookie((string) config('session.cookie'))) {
                $redirect->with(
                    'session_expired',
                    'Your login session expired. Sign in again to continue.',
                );
            }

            return $redirect;
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            $message = 'Your session expired while this page was open. Reload and try again.';

            return $request->expectsJson() || $request->header('X-Inertia')
                ? response()->json(['message' => $message, 'code' => 'SESSION_EXPIRED'], 419)
                : back()->with('session_expired', $message);
        });
    })->create();
