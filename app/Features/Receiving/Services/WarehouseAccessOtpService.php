<?php

namespace App\Features\Receiving\Services;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WarehouseAccessOtp;
use App\Notifications\WarehouseAccessOtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class WarehouseAccessOtpService
{
    public function __construct(
        private readonly ReceivingSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function issue(User $user, ?string $ip = null, bool $resend = false): void
    {
        abort_unless($user->can(Permission::AccessWarehouse->value), 403);

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
            $user->notify(new WarehouseAccessOtpCode($code, $expiresMinutes));
        } catch (Throwable $error) {
            $this->activity->record(
                'email',
                $resend ? 'warehouse_otp_resend_failed' : 'warehouse_otp_send_failed',
                'error',
                "Warehouse verification code could not be sent to {$user->email}.",
                $user,
                null,
                $ip,
                $error,
            );

            throw $error;
        }

        $this->activity->record(
            'email',
            $resend ? 'warehouse_otp_resent' : 'warehouse_otp_sent',
            'success',
            "Warehouse verification code was sent to {$user->email}.",
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
                $this->activity->record('auth', 'warehouse_otp_failed', 'warning', 'Warehouse verification code was expired or unavailable.', $user, null, $ip);

                return false;
            }

            if (! Hash::check($code, $otp->otp_hash)) {
                $otp->increment('attempt_count');
                $this->activity->record('auth', 'warehouse_otp_failed', 'warning', 'An incorrect warehouse verification code was entered.', $user, null, $ip);

                return false;
            }

            $otp->forceFill(['used_at' => now()])->save();
            $this->activity->record('auth', 'warehouse_otp_verified', 'success', "{$user->email} verified warehouse access with an email OTP.", $user, null, $ip);

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
