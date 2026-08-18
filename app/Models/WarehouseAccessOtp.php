<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $otp_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempt_count
 * @property CarbonImmutable|null $used_at
 */
#[Fillable(['user_id', 'email', 'otp_hash', 'expires_at', 'attempt_count', 'used_at'])]
class WarehouseAccessOtp extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
