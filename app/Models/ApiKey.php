<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $public_id
 * @property string $token_hash
 * @property array<int, string> $abilities
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'name', 'public_id', 'token_hash', 'abilities', 'last_used_at', 'expires_at', 'revoked_at',
])]
#[Hidden(['token_hash'])]
class ApiKey extends Model
{
    public const ABILITY_READ_CORRECTED_DATA = 'corrected-data:read';

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function permits(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
