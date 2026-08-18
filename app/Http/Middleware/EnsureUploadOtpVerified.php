<?php

namespace App\Http\Middleware;

use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\OtpDeviceRemember;
use App\Features\Receiving\Services\UploadOtpGrant;
use App\Models\UploadType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUploadOtpVerified
{
    public function __construct(
        private readonly UploadOtpGrant $grant,
        private readonly ActivityLogger $activity,
        private readonly OtpDeviceRemember $remember,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $uploadType = $request->route('uploadType');
        $user = $request->user();

        abort_unless($uploadType instanceof UploadType && $user instanceof User, 403);

        if (! $uploadType->is_active) {
            return redirect()->route('receiving.upload.show', $uploadType);
        }

        if (! $user->canAccessUploadType($uploadType)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'You are not authorized to access this receiving page.');
            }

            return redirect()->route('dashboard');
        }

        $grantIsValid = $request->header('X-Live-Refresh') === '1'
            ? $this->grant->valid($request, $uploadType)
            : $this->grant->refresh($request, $uploadType);

        if (! $grantIsValid) {
            // Auto-grant when a valid remember-device cookie is present.
            if ($this->remember->check($request, "upload_{$uploadType->getKey()}", $user)) {
                $this->grant->grant($request, $uploadType);

                return $next($request);
            }

            $expired = $this->grant->expired($request, $uploadType);
            if ($expired) {
                $this->activity->record('auth', 'upload_session_expired', 'warning', "{$user->email}'s {$uploadType->name} upload session expired.", $user, null, $request);
                $this->grant->revoke($request, $uploadType);
                $request->session()->flash(
                    'session_expired',
                    "Your {$uploadType->name} upload verification expired. Enter a new email code to continue.",
                );
            }

            if ($request->expectsJson() || ($expired && $request->header('X-Inertia'))) {
                return response()->json([
                    'message' => $expired
                        ? 'Your upload verification session expired. Verify access again.'
                        : 'Email verification is required before uploading.',
                    'code' => $expired ? 'SESSION_EXPIRED' : 'OTP_REQUIRED',
                ], $expired ? 419 : 403);
            }

            return redirect()->route('receiving.upload.show', $uploadType);
        }

        return $next($request);
    }
}
