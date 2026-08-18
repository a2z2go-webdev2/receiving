<?php

namespace App\Http\Controllers\Driver;

use App\Features\Driver\Services\DriverAccessOtpGrant;
use App\Features\Driver\Services\DriverAccessOtpService;
use App\Features\Receiving\Services\OtpDeviceRemember;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\VerifyDriverAccessOtpRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DriverAccessOtpController extends Controller
{
    private const DELIVERY_ERROR = 'We could not send the verification email. Check your mail settings or try sending a new code.';

    public function show(
        Request $request,
        DriverAccessOtpGrant $grant,
        DriverAccessOtpService $otp,
        ReceivingSettings $settings,
        OtpDeviceRemember $remember,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($grant->refresh($request)) {
            return redirect()->intended(route('driver.dashboard'));
        }

        // Auto-grant when a valid remember-device cookie is present.
        if ($remember->check($request, 'driver', $user)) {
            $grant->grant($request);

            return redirect()->intended(route('driver.dashboard'));
        }

        $deliveryError = null;

        if (! $otp->hasLiveCode($user)) {
            try {
                $otp->issue($user, $request->ip());
            } catch (Throwable) {
                $deliveryError = self::DELIVERY_ERROR;
            }
        }

        return Inertia::render('driver/otp', [
            'maskedEmail' => $this->maskEmail($user->email),
            'expiresMinutes' => (int) $settings->get('otp_expiration_minutes'),
            'deliveryError' => $deliveryError,
        ]);
    }

    public function verify(
        VerifyDriverAccessOtpRequest $request,
        DriverAccessOtpService $otp,
        DriverAccessOtpGrant $grant,
        OtpDeviceRemember $remember,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $otp->verify($user, $request->string('code')->toString(), $request->ip())) {
            return back()->withErrors(['code' => 'The OTP is incorrect or has expired.']);
        }

        $request->session()->regenerate();
        $grant->grant($request);

        $response = redirect()->intended(route('driver.dashboard'));

        if ($request->boolean('remember')) {
            $response->withCookie($remember->cookie('driver', $user));
        }

        return $response;
    }

    public function resend(Request $request, DriverAccessOtpService $otp): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        try {
            $otp->issue($user, $request->ip(), true);
        } catch (Throwable) {
            return back()->withErrors(['resend' => self::DELIVERY_ERROR]);
        }

        return back()->with('status', 'A new driver verification code was sent.');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).str_repeat('•', max(2, strlen($local) - 1)).'@'.$domain;
    }
}
