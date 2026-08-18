<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $warehouse_delivery_id
 * @property int $warehouse_item_id
 * @property string $quantity
 * @property string|null $unit
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WarehouseDelivery $delivery
 * @property-read WarehouseItem $item
 * @property-read Collection<int, WarehouseAllocation> $allocations
 */
#[Fillable(['warehouse_delivery_id', 'warehouse_item_id', 'quantity', 'unit'])]
class WarehouseDeliveryLine extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    /** @return BelongsTo<WarehouseDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WarehouseDelivery::class, 'warehouse_delivery_id');
    }

    /** @return BelongsTo<WarehouseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    /** @return HasMany<WarehouseAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(WarehouseAllocation::class);
    }
}
