<?php

namespace App\Features\Receiving\Services;

use App\Models\UploadType;
use Illuminate\Http\Request;

class UploadOtpGrant
{
    public function grant(Request $request, UploadType $uploadType): void
    {
        $request->session()->put($this->key($uploadType), now()->getTimestamp());
    }

    public function valid(Request $request, UploadType $uploadType): bool
    {
        $verifiedAt = $request->session()->get($this->key($uploadType));

        return is_int($verifiedAt) && $this->timestampIsValid($verifiedAt);
    }

    public function refresh(Request $request, UploadType $uploadType): bool
    {
        if (! $this->valid($request, $uploadType)) {
            return false;
        }

        $this->grant($request, $uploadType);

        return true;
    }

    public function expired(Request $request, UploadType $uploadType): bool
    {
        $verifiedAt = $request->session()->get($this->key($uploadType));

        return is_int($verifiedAt) && ! $this->timestampIsValid($verifiedAt);
    }

    /** @return array<int, int> */
    public function activeUploadTypeIds(Request $request): array
    {
        $grants = $request->session()->get('receiving.otp_grants', []);
        if (! is_array($grants)) {
            return [];
        }

        $ids = [];

        foreach ($grants as $id => $verifiedAt) {
            if (! is_int($verifiedAt) || ! $this->timestampIsValid($verifiedAt)) {
                continue;
            }

            if (ctype_digit((string) $id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function revoke(Request $request, UploadType $uploadType): void
    {
        $request->session()->forget($this->key($uploadType));
    }

    private function key(UploadType $uploadType): string
    {
        return "receiving.otp_grants.{$uploadType->getKey()}";
    }

    private function timestampIsValid(int $verifiedAt): bool
    {
        $ageInSeconds = now()->getTimestamp() - $verifiedAt;

        return $ageInSeconds >= 0
            && $ageInSeconds <= (int) config('receiving.otp.grant_minutes', 30) * 60;
    }
}
