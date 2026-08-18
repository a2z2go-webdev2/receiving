<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\AdminAccessOtpGrant;
use App\Features\Receiving\Services\OtpDeviceRemember;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminOtpVerified
{
    public function __construct(
        private readonly AdminAccessOtpGrant $grant,
        private readonly ActivityLogger $activity,
        private readonly OtpDeviceRemember $remember,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $user->can(Permission::AccessAdmin->value)) {
            return $next($request);
        }

        $grantIsValid = $request->header('X-Live-Refresh') === '1'
            ? $this->grant->valid($request)
            : $this->grant->refresh($request);

        if ($grantIsValid) {
            return $next($request);
        }

        // Auto-grant when a valid remember-device cookie is present.
        if ($this->remember->check($request, 'admin', $user)) {
            $this->grant->grant($request);

            return $next($request);
        }

        $expired = $this->grant->expired($request);
        if ($expired) {
            $this->activity->record('auth', 'admin_otp_session_expired', 'warning', "{$user->email}'s admin OTP session expired.", $user, null, $request);
            $this->grant->revoke($request);
            $request->session()->flash(
                'session_expired',
                'Your admin verification session expired. Enter a new email code to continue.',
            );
        }

        if ($request->expectsJson() || ($expired && $request->header('X-Inertia'))) {
            return response()->json([
                'message' => $expired
                    ? 'Your admin verification session expired. Verify access again.'
                    : 'Email OTP verification is required for this area.',
                'code' => $expired ? 'SESSION_EXPIRED' : 'OTP_REQUIRED',
            ], $expired ? 419 : 403);
        }

        $intended = '/'.$request->path();
        if ($request->getQueryString()) {
            $intended .= '?'.$request->getQueryString();
        }
        $request->session()->put('url.intended', $intended);

        return redirect()->route('admin.otp.show');
    }
}
