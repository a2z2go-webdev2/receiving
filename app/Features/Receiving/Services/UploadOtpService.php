<?php

namespace App\Features\Receiving\Services;

use App\Models\UploadOtp;
use App\Models\UploadType;
use App\Models\User;
use App\Notifications\UploadOtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class UploadOtpService
{
    public function __construct(
        private readonly ReceivingSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function issue(User $user, UploadType $uploadType, ?string $ip = null, bool $resend = false): void
    {
        abort_unless($user->canAccessUploadType($uploadType), 403, 'You are not authorized to access this receiving page.');

        $expiresMinutes = (int) $this->settings->get('otp_expiration_minutes');
        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $uploadType, $expiresMinutes, $code): void {
            UploadOtp::query()
                ->where('user_id', $user->getKey())
                ->where('upload_type_id', $uploadType->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            UploadOtp::query()->create([
                'user_id' => $user->getKey(),
                'upload_type_id' => $uploadType->getKey(),
                'email' => $user->email,
                'otp_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($expiresMinutes),
                'attempt_count' => 0,
            ]);
        });

        try {
            $user->notify(new UploadOtpCode($code, $uploadType, $expiresMinutes));
        } catch (Throwable $error) {
            $this->activity->record(
                'email',
                $resend ? 'upload_otp_resend_failed' : 'upload_otp_send_failed',
                'error',
                "Verification code email for {$uploadType->name} upload access could not be sent to {$user->email}.",
                $user,
                null,
                $ip,
                $error,
            );

            throw $error;
        }

        $this->activity->record(
            'email',
            $resend ? 'upload_otp_resent' : 'upload_otp_sent',
            'success',
            $resend
                ? "{$user->email} requested a new verification code for {$uploadType->name} upload access."
                : "Verification code sent to {$user->email} for {$uploadType->name} upload access.",
            $user,
            null,
            $ip,
        );
    }

    public function verify(User $user, UploadType $uploadType, string $code, ?string $ip = null): bool
    {
        $maxAttempts = (int) config('receiving.otp.max_attempts', 5);

        return DB::transaction(function () use ($user, $uploadType, $code, $ip, $maxAttempts): bool {
            $otp = UploadOtp::query()
                ->where('user_id', $user->getKey())
                ->where('upload_type_id', $uploadType->getKey())
                ->whereNull('used_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->expires_at->isPast() || $otp->attempt_count >= $maxAttempts) {
                $this->activity->record('auth', 'upload_otp_failed', 'warning', "Verification code for {$uploadType->name} upload access was expired or unavailable.", $user, null, $ip);

                return false;
            }

            if (! Hash::check($code, $otp->otp_hash)) {
                $otp->increment('attempt_count');
                $this->activity->record('auth', 'upload_otp_failed', 'warning', "Incorrect verification code entered for {$uploadType->name} upload access.", $user, null, $ip);

                return false;
            }

            $otp->forceFill(['used_at' => now()])->save();
            $this->activity->record('auth', 'upload_access_logged_in', 'success', "{$user->email} signed in to the {$uploadType->name} upload page.", $user, null, $ip);

            return true;
        });
    }

    public function hasLiveCode(User $user, UploadType $uploadType): bool
    {
        return UploadOtp::query()
            ->where('user_id', $user->getKey())
            ->where('upload_type_id', $uploadType->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
