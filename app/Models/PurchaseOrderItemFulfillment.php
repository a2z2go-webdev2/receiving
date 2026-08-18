<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_item_schedule_id
 * @property int $po_extraction_id
 * @property int $po_extraction_item_id
 * @property int $receiving_upload_id
 * @property string|null $po_number
 * @property CarbonImmutable|null $po_date
 * @property int|null $po_week
 * @property string $ordered_quantity
 * @property string|null $unit
 * @property string $matched_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PurchaseOrderItemSchedule $schedule
 * @property-read PoExtraction $poExtraction
 * @property-read PoExtractionItem $poItem
 * @property-read ReceivingUpload $upload
 */
#[Fillable([
    'purchase_order_item_schedule_id', 'po_extraction_id', 'po_extraction_item_id',
    'receiving_upload_id', 'po_number', 'po_date', 'po_week', 'ordered_quantity',
    'unit', 'matched_by',
])]
class PurchaseOrderItemFulfillment extends Model
{
    protected function casts(): array
    {
        return [
            'po_date' => 'immutable_date',
            'po_week' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItemSchedule::class, 'purchase_order_item_schedule_id');
    }

    public function poExtraction(): BelongsTo
    {
        return $this->belongsTo(PoExtraction::class);
    }

    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PoExtractionItem::class, 'po_extraction_item_id');
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'receiving_upload_id');
    }
}
