<?php

namespace App\Models;

use App\Enums\WarehouseAllocationMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $warehouse_delivery_line_id
 * @property int $warehouse_stock_lot_id
 * @property string $quantity_allocated
 * @property WarehouseAllocationMethod $allocation_method
 * @property int|null $allocated_by_user_id
 * @property CarbonImmutable $allocated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WarehouseDeliveryLine $deliveryLine
 * @property-read WarehouseStockLot $stockLot
 */
#[Fillable([
    'warehouse_delivery_line_id', 'warehouse_stock_lot_id', 'quantity_allocated',
    'allocation_method', 'allocated_by_user_id', 'allocated_at',
])]
class WarehouseAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_allocated' => 'decimal:3',
            'allocation_method' => WarehouseAllocationMethod::class,
            'allocated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WarehouseDeliveryLine, $this> */
    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(WarehouseDeliveryLine::class, 'warehouse_delivery_line_id');
    }

    /** @return BelongsTo<WarehouseStockLot, $this> */
    public function stockLot(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockLot::class, 'warehouse_stock_lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }
}
