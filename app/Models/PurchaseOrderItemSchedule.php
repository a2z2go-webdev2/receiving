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
 * @property int|null $serial_number
 * @property string|null $sku_number
 * @property string|null $sku_number_normalized
 * @property string|null $ean_barcode
 * @property string|null $ean_barcode_normalized
 * @property string $description
 * @property string $description_normalized
 * @property string $target_quantity
 * @property string|null $package_quantity
 * @property string|null $package_unit
 * @property string|null $sold_quantity
 * @property string|null $unit
 * @property int|null $expected_week
 * @property bool $is_special_order
 * @property bool $is_active
 * @property string|null $notes
 * @property string $source
 * @property string|null $source_key
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, PurchaseOrderItemFulfillment> $fulfillments
 */
#[Fillable([
    'serial_number', 'sku_number', 'sku_number_normalized', 'ean_barcode',
    'ean_barcode_normalized', 'description', 'description_normalized',
    'target_quantity', 'package_quantity', 'package_unit', 'sold_quantity',
    'unit', 'expected_week', 'is_special_order', 'is_active',
    'notes', 'source', 'source_key', 'created_by',
])]
class PurchaseOrderItemSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'expected_week' => 'integer',
            'is_special_order' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemFulfillment::class);
    }
}
