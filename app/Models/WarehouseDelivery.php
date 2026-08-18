<?php

namespace App\Models;

use App\Enums\WarehouseDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string|null $shipment_reference
 * @property string|null $ship_ref
 * @property string $customer_name
 * @property string|null $delivery_reference
 * @property string|null $sales_order
 * @property string|null $po
 * @property WarehouseDeliveryStatus $status
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $created_by_user_id
 * @property int|null $dispatched_by_user_id
 * @property int|null $delivered_by_user_id
 * @property string|null $delivery_location
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, WarehouseDeliveryLine> $lines
 */
#[Fillable([
    'shipment_reference', 'customer_name', 'delivery_reference', 'sales_order', 'po', 'status', 'dispatched_at', 'delivered_at', 'delivery_location',
    'created_by_user_id', 'dispatched_by_user_id', 'delivered_by_user_id', 'notes',
])]
class WarehouseDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'status' => WarehouseDeliveryStatus::class,
            'dispatched_at' => 'immutable_date',
            'delivered_at' => 'immutable_date',
        ];
    }

    /** @return HasMany<WarehouseDeliveryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseDeliveryLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }
}
