<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $identity_key
 * @property string|null $sku_number
 * @property string|null $sku_number_normalized
 * @property string $description
 * @property string $description_normalized
 * @property string|null $base_unit
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, WarehouseStockLot> $stockLots
 * @property-read Collection<int, WarehouseDeliveryLine> $deliveryLines
 */
#[Fillable([
    'identity_key', 'sku_number', 'sku_number_normalized', 'description',
    'description_normalized', 'base_unit',
])]
class WarehouseItem extends Model
{
    /** @return HasMany<WarehouseStockLot, $this> */
    public function stockLots(): HasMany
    {
        return $this->hasMany(WarehouseStockLot::class);
    }

    /** @return HasMany<WarehouseDeliveryLine, $this> */
    public function deliveryLines(): HasMany
    {
        return $this->hasMany(WarehouseDeliveryLine::class);
    }
}
