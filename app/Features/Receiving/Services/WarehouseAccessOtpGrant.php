<?php

namespace App\Features\Receiving\Services;

use Illuminate\Http\Request;

class WarehouseAccessOtpGrant
{
    private const SESSION_KEY = 'warehouse.otp_verified_at';

    public function grant(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    public function valid(Request $request): bool
    {
        $verifiedAt = $request->session()->get(self::SESSION_KEY);

        return is_int($verifiedAt) && $this->timestampIsValid($verifiedAt);
    }

    public function refresh(Request $request): bool
    {
        if (! $this->valid($request)) {
            return false;
        }

        $this->grant($request);

        return true;
    }

    public function expired(Request $request): bool
    {
        $verifiedAt = $request->session()->get(self::SESSION_KEY);

        return is_int($verifiedAt) && ! $this->timestampIsValid($verifiedAt);
    }

    public function revoke(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    private function timestampIsValid(int $verifiedAt): bool
    {
        $ageInSeconds = now()->getTimestamp() - $verifiedAt;

        return $ageInSeconds >= 0
            && $ageInSeconds <= (int) config('receiving.otp.grant_minutes', 30) * 60;
    }
}
