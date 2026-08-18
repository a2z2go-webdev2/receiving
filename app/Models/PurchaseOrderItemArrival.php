<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string|null $source_key
 * @property int $purchase_order_document_link_id
 * @property int $po_extraction_id
 * @property int $ai_extraction_id
 * @property int $receiving_upload_id
 * @property int|null $po_extraction_item_id
 * @property int|null $purchase_order_item_schedule_id
 * @property string|null $po_number
 * @property CarbonImmutable|null $po_date
 * @property CarbonImmutable|null $arrival_date
 * @property int|null $po_week
 * @property string|null $item_code
 * @property string|null $item_description
 * @property string $arrived_quantity
 * @property string|null $ordered_quantity
 * @property string|null $target_quantity
 * @property string|null $unit
 * @property string $matched_by
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AiExtraction $aiExtraction
 * @property-read ReceivingUpload $upload
 * @property-read PurchaseOrderItemSchedule|null $schedule
 */
#[Fillable([
    'source_key', 'purchase_order_document_link_id', 'po_extraction_id', 'ai_extraction_id',
    'receiving_upload_id', 'po_extraction_item_id', 'purchase_order_item_schedule_id',
    'po_number', 'po_date', 'arrival_date', 'po_week', 'item_code', 'item_description',
    'arrived_quantity', 'ordered_quantity', 'target_quantity', 'unit', 'matched_by',
    'status',
])]
class PurchaseOrderItemArrival extends Model
{
    protected function casts(): array
    {
        return [
            'po_date' => 'immutable_date',
            'arrival_date' => 'immutable_date',
            'po_week' => 'integer',
        ];
    }

    /** @param  Builder<PurchaseOrderItemArrival>  $query */
    public function scopeAwaitingWarehouseConfirmation(Builder $query): Builder
    {
        return $query
            ->whereNotNull('source_key')
            ->whereNotIn('source_key', WarehouseStockLot::query()
                ->whereNotNull('source_key')
                ->select('source_key'));
    }

    /** @return BelongsTo<PurchaseOrderDocumentLink, $this> */
    public function documentLink(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDocumentLink::class, 'purchase_order_document_link_id');
    }

    /** @return BelongsTo<PoExtraction, $this> */
    public function poExtraction(): BelongsTo
    {
        return $this->belongsTo(PoExtraction::class);
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

    /** @return BelongsTo<PoExtractionItem, $this> */
    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PoExtractionItem::class, 'po_extraction_item_id');
    }

    /** @return BelongsTo<PurchaseOrderItemSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItemSchedule::class, 'purchase_order_item_schedule_id');
    }

    /** @return HasOne<WarehouseStockLot, $this> */
    public function stockLot(): HasOne
    {
        return $this->hasOne(WarehouseStockLot::class, 'purchase_order_item_arrival_id');
    }
}
