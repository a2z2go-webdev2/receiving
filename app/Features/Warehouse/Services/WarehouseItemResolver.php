<?php

namespace App\Features\Warehouse\Services;

use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Models\PurchaseOrderItemArrival;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\WarehouseItem;

class WarehouseItemResolver
{
    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    public function forArrival(PurchaseOrderItemArrival $arrival): WarehouseItem
    {
        $arrival->loadMissing('schedule');
        $schedule = $arrival->purchase_order_item_schedule_id === null ? null : $arrival->schedule;
        $sku = $schedule instanceof PurchaseOrderItemSchedule ? $schedule->sku_number : $arrival->item_code;
        $description = $schedule instanceof PurchaseOrderItemSchedule ? $schedule->description : $arrival->item_description;
        $unit = $schedule instanceof PurchaseOrderItemSchedule ? $schedule->unit : $arrival->unit;

        return $this->resolve(
            $sku,
            $description ?: "Unidentified item from PO {$arrival->po_number}",
            $unit,
            $arrival->source_key ?? "arrival:{$arrival->getKey()}",
        );
    }

    public function forOpeningStock(?WarehouseItem $existing, ?string $sku, string $description, ?string $unit): WarehouseItem
    {
        if ($existing !== null) {
            return $existing;
        }

        return $this->resolve($sku, $description, $unit, null);
    }

    private function resolve(?string $sku, string $description, ?string $unit, ?string $fallback): WarehouseItem
    {
        $sku = $this->meaningful($sku);
        $description = trim($description);
        $unit = $this->meaningful($unit);
        $normalizedSku = $this->normalizer->normalizeIdentifier($sku);
        $normalizedDescription = $this->normalizer->normalizeDescription($description) ?? '';
        $normalizedUnit = $this->normalizer->normalizeIdentifier($unit) ?? '';
        $descriptionIdentity = $normalizedDescription !== ''
            ? "{$normalizedDescription}|{$normalizedUnit}"
            : 'fallback:'.($fallback ?? $description);
        $identityKey = $normalizedSku !== null
            ? "sku:{$normalizedSku}"
            : 'description:'.hash('sha256', $descriptionIdentity);

        $item = WarehouseItem::query()->firstOrCreate(
            ['identity_key' => $identityKey],
            [
                'sku_number' => $sku,
                'sku_number_normalized' => $normalizedSku,
                'description' => $description,
                'description_normalized' => $normalizedDescription,
                'base_unit' => $unit,
            ],
        );

        $updates = [];
        if ($item->sku_number === null && $sku !== null) {
            $updates['sku_number'] = $sku;
            $updates['sku_number_normalized'] = $normalizedSku;
        }
        if ($item->base_unit === null && $unit !== null) {
            $updates['base_unit'] = $unit;
        }
        if ($updates !== []) {
            $item->forceFill($updates)->save();
        }

        return $item;
    }

    private function meaningful(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === '[see image]' ? null : $value;
    }
}
