<?php

namespace App\Features\Warehouse\Services;

use App\Models\WarehouseItem;
use App\Models\WarehouseStockLot;
use Illuminate\Pagination\LengthAwarePaginator;

class WarehouseInventoryView
{
    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function inventoryPage(?string $search = null): LengthAwarePaginator
    {
        return WarehouseItem::query()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('sku_number', 'like', "%{$search}%");
                });
            })
            ->whereHas('stockLots')
            ->with(['stockLots' => fn ($query) => $query
                ->withSum('allocations', 'quantity_allocated')
                ->orderByRaw('CASE WHEN received_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('received_at')
                ->orderBy('id')])
            ->orderBy('description')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (WarehouseItem $item): array => $this->serialize($item));
    }

    /** @return array<int, array<string, mixed>> */
    public function availableItems(): array
    {
        return WarehouseItem::query()
            ->whereHas('stockLots')
            ->with(['stockLots' => fn ($query) => $query->withSum('allocations', 'quantity_allocated')])
            ->orderBy('description')
            ->limit(200)
            ->get()
            ->map(fn (WarehouseItem $item): array => $this->serialize($item))
            ->filter(fn (array $item): bool => $item['available_quantity'] > 0)
            ->values()
            ->all();
    }

    /** @return array<int, array{id: int, sku_number: string|null, description: string, unit: string|null}> */
    public function itemOptions(): array
    {
        return WarehouseItem::query()
            ->orderBy('description')
            ->limit(200)
            ->get(['id', 'sku_number', 'description', 'base_unit'])
            ->map(fn (WarehouseItem $item): array => [
                'id' => $item->getKey(),
                'sku_number' => $item->sku_number,
                'description' => $item->description,
                'unit' => $item->base_unit,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialize(WarehouseItem $item): array
    {
        $received = $item->stockLots->sum(fn (WarehouseStockLot $lot): float => (float) $lot->quantity_received);
        $allocated = $item->stockLots->sum(
            fn (WarehouseStockLot $lot): float => (float) ($lot->getAttribute('allocations_sum_quantity_allocated') ?? 0),
        );

        $activeLots = $item->stockLots->filter(function (WarehouseStockLot $lot): bool {
            $lotReceived = (float) $lot->quantity_received;
            $lotAllocated = (float) ($lot->getAttribute('allocations_sum_quantity_allocated') ?? 0);

            return ($lotReceived - $lotAllocated) > 0.0001;
        });

        return [
            'id' => $item->getKey(),
            'sku_number' => $item->sku_number,
            'description' => $item->description,
            'unit' => $item->base_unit,
            'received_quantity' => $received,
            'allocated_quantity' => $allocated,
            'available_quantity' => max(0, $received - $allocated),
            'lot_count' => $activeLots->count(),
            'unknown_date_lots' => $activeLots->whereNull('received_at')->count(),
            'oldest_received_at' => $activeLots->pluck('received_at')->filter()->sort()->first()?->toDateString(),
        ];
    }
}
