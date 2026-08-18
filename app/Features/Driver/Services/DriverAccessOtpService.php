<?php

namespace App\Features\Driver\Services;

use App\Enums\Permission;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Models\User;
use App\Models\WarehouseAccessOtp;
use App\Notifications\DriverAccessOtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class DriverAccessOtpService
{
    public function __construct(
        private readonly ReceivingSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function issue(User $user, ?string $ip = null, bool $resend = false): void
    {
        abort_unless($user->can(Permission::AccessDriver->value), 403);

        $expiresMinutes = (int) $this->settings->get('otp_expiration_minutes');
        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $expiresMinutes, $code): void {
            WarehouseAccessOtp::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            WarehouseAccessOtp::query()->create([
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'otp_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($expiresMinutes),
                'attempt_count' => 0,
            ]);
        });

        try {
            $user->notify(new DriverAccessOtpCode($code, $expiresMinutes));
        } catch (Throwable $error) {
            $this->activity->record(
                'email',
                $resend ? 'driver_otp_resend_failed' : 'driver_otp_send_failed',
                'error',
                "driver verification code could not be sent to {$user->email}.",
                $user,
                null,
                $ip,
                $error,
            );

            throw $error;
        }

        $this->activity->record(
            'email',
            $resend ? 'driver_otp_resent' : 'driver_otp_sent',
            'success',
            "driver verification code was sent to {$user->email}.",
            $user,
            null,
            $ip,
        );
    }

    public function verify(User $user, string $code, ?string $ip = null): bool
    {
        $maxAttempts = (int) config('receiving.otp.max_attempts', 5);

        return DB::transaction(function () use ($user, $code, $ip, $maxAttempts): bool {
            $otp = WarehouseAccessOtp::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->expires_at->isPast() || $otp->attempt_count >= $maxAttempts) {
                $this->activity->record('auth', 'driver_otp_failed', 'warning', 'driver verification code was expired or unavailable.', $user, null, $ip);

                return false;
            }

            if (! Hash::check($code, $otp->otp_hash)) {
                $otp->increment('attempt_count');
                $this->activity->record('auth', 'driver_otp_failed', 'warning', 'An incorrect driver verification code was entered.', $user, null, $ip);

                return false;
            }

            $otp->forceFill(['used_at' => now()])->save();
            $this->activity->record('auth', 'driver_otp_verified', 'success', "{$user->email} verified driver access with an email OTP.", $user, null, $ip);

            return true;
        });
    }

    public function hasLiveCode(User $user): bool
    {
        return WarehouseAccessOtp::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
