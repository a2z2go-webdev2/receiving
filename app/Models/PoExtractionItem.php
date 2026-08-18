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
 * @property int $po_extraction_id
 * @property int $sort_order
 * @property string|null $item_code
 * @property string|null $product_description
 * @property string|null $package
 * @property string|null $quantity
 * @property string|null $unit
 * @property string|null $unit_price
 * @property string|null $line_total
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PoExtraction $extraction
 * @property-read Collection<int, PurchaseOrderItemFulfillment> $fulfillments
 */
#[Fillable([
    'po_extraction_id', 'sort_order', 'item_code', 'product_description',
    'package', 'quantity', 'unit', 'unit_price', 'line_total',
])]
class PoExtractionItem extends Model
{
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(PoExtraction::class, 'po_extraction_id');
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemFulfillment::class);
    }
}
