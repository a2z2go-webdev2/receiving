<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property string|null $from_status
 * @property string $to_status
 * @property CarbonImmutable $event_date
 * @property int|null $actor_user_id
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'aggregate_type', 'aggregate_id', 'from_status', 'to_status',
    'event_date', 'actor_user_id', 'metadata',
])]
class WarehouseProgressEvent extends Model
{
    protected function casts(): array
    {
        return [
            'event_date' => 'immutable_date',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
