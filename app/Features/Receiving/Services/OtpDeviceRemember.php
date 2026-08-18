<?php

namespace App\Features\Receiving\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Manages long-lived "remember this device" cookies for OTP areas.
 *
 * Each OTP area (admin, upload, warehouse) gets its own cookie name so
 * they can be granted and revoked independently. The cookie value is an
 * encrypted JSON payload containing the user ID and the creation timestamp.
 * Laravel's EncryptCookies middleware handles encryption/decryption and
 * tamper detection automatically.
 */
class OtpDeviceRemember
{
    /**
     * Create a remember-device cookie for the given area.
     */
    public function cookie(string $area, User $user): Cookie
    {
        $days = (int) config('receiving.otp.remember_days', 30);
        $minutes = $days * 24 * 60;

        $payload = Crypt::encryptString(json_encode([
            'user_id' => $user->getKey(),
            'created_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return cookie(
            name: $this->cookieName($area),
            value: $payload,
            minutes: $minutes,
            secure: true,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * Check whether the request carries a valid remember-device cookie
     * for the given area that matches the current user.
     */
    public function check(Request $request, string $area, User $user): bool
    {
        $raw = $request->cookie($this->cookieName($area));

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $data = json_decode(Crypt::decryptString($raw), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        if (! is_array($data) || ! isset($data['user_id'], $data['created_at'])) {
            return false;
        }

        if ((int) $data['user_id'] !== $user->getKey()) {
            return false;
        }

        $days = (int) config('receiving.otp.remember_days', 30);
        $ageSeconds = now()->getTimestamp() - (int) $data['created_at'];

        return $ageSeconds >= 0 && $ageSeconds <= $days * 86400;
    }

    /**
     * Build the cookie name for a given OTP area.
     */
    public function cookieName(string $area): string
    {
        return "{$area}_otp_remembered";
    }
}
