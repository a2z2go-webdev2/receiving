<?php

namespace App\Models;

use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseStockSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $warehouse_item_id
 * @property WarehouseStockSource $source_type
 * @property string $source_key
 * @property int|null $purchase_order_item_arrival_id
 * @property int|null $ai_extraction_id
 * @property int|null $receiving_upload_id
 * @property string|null $po_number
 * @property string|null $lot_number
 * @property string $quantity_received
 * @property CarbonImmutable|null $received_at
 * @property WarehouseDateQuality $received_date_quality
 * @property int|null $confirmed_by_user_id
 * @property CarbonImmutable $confirmed_at
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WarehouseItem $item
 * @property-read Collection<int, WarehouseAllocation> $allocations
 */
#[Fillable([
    'warehouse_item_id', 'source_type', 'source_key', 'purchase_order_item_arrival_id',
    'ai_extraction_id', 'receiving_upload_id', 'po_number', 'lot_number',
    'quantity_received', 'received_at', 'received_date_quality', 'confirmed_by_user_id',
    'confirmed_at', 'notes',
])]
class WarehouseStockLot extends Model
{
    protected function casts(): array
    {
        return [
            'source_type' => WarehouseStockSource::class,
            'quantity_received' => 'decimal:3',
            'received_at' => 'immutable_date',
            'received_date_quality' => WarehouseDateQuality::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WarehouseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    /** @return BelongsTo<PurchaseOrderItemArrival, $this> */
    public function sourceArrival(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItemArrival::class, 'purchase_order_item_arrival_id');
    }

    /** @return BelongsTo<AiExtraction, $this> */
    public function aiExtraction(): BelongsTo
    {
        return $this->belongsTo(AiExtraction::class);
    }

    /** @return BelongsTo<ReceivingUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'receiving_upload_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /** @return HasMany<WarehouseAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(WarehouseAllocation::class);
    }
}
