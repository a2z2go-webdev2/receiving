<?php

namespace App\Features\Warehouse\Services;

use App\Enums\WarehouseDeliveryStatus;
use App\Models\WarehouseAllocation;
use App\Models\WarehouseDeliveryLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class WarehouseDwellReport
{
    /** @return array{rows: mixed, summary: array{delivered_lines: int, fully_dated_lines: int, date_coverage_percent: float, average_line_warehouse_holding_days: float|null, average_line_warehouse_dwell_days: float|null, maximum_warehouse_dwell_days: int|null}} */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = $this->query($from, $to);
        $deliveredLines = 0;
        $fullyDatedLines = 0;
        $warehouseHoldingSum = 0.0;
        $warehouseHoldingCount = 0;
        $warehouseDwellSum = 0.0;
        $warehouseDwellCount = 0;
        $maximumWarehouseDwell = null;

        (clone $query)->orderBy('warehouse_delivery_lines.id')->chunkById(250, function ($lines) use (
            &$deliveredLines,
            &$fullyDatedLines,
            &$warehouseHoldingSum,
            &$warehouseHoldingCount,
            &$warehouseDwellSum,
            &$warehouseDwellCount,
            &$maximumWarehouseDwell,
        ): void {
            foreach ($lines as $line) {
                if (! $line instanceof WarehouseDeliveryLine) {
                    continue;
                }
                $row = $this->serialize($line);
                $deliveredLines++;
                if ($row['date_coverage_percent'] >= 100.0) {
                    $fullyDatedLines++;
                }
                if ($row['warehouse_holding_days'] !== null) {
                    $warehouseHoldingSum += $row['warehouse_holding_days'];
                    $warehouseHoldingCount++;
                }
                if ($row['warehouse_dwell_days'] !== null) {
                    $warehouseDwellSum += $row['warehouse_dwell_days'];
                    $warehouseDwellCount++;
                }
                if ($row['maximum_warehouse_dwell_days'] !== null) {
                    $maximumWarehouseDwell = max($maximumWarehouseDwell ?? 0, $row['maximum_warehouse_dwell_days']);
                }
            }
        }, 'warehouse_delivery_lines.id', 'id');

        $rows = (clone $query)
            ->orderByDesc('warehouse_delivery_lines.id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (WarehouseDeliveryLine $line): array => $this->serialize($line));

        return [
            'rows' => $rows,
            'summary' => [
                'delivered_lines' => $deliveredLines,
                'fully_dated_lines' => $fullyDatedLines,
                'date_coverage_percent' => $deliveredLines === 0 ? 0.0 : round(($fullyDatedLines / $deliveredLines) * 100, 1),
                'average_line_warehouse_holding_days' => $warehouseHoldingCount === 0 ? null : round($warehouseHoldingSum / $warehouseHoldingCount, 1),
                'average_line_warehouse_dwell_days' => $warehouseDwellCount === 0 ? null : round($warehouseDwellSum / $warehouseDwellCount, 1),
                'maximum_warehouse_dwell_days' => $maximumWarehouseDwell,
            ],
        ];
    }

    /** @return Builder<WarehouseDeliveryLine> */
    private function query(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return WarehouseDeliveryLine::query()
            ->select('warehouse_delivery_lines.*')
            ->whereHas('delivery', fn (Builder $query) => $query
                ->where('status', WarehouseDeliveryStatus::Delivered->value)
                ->whereBetween('delivered_at', [$from->toDateString(), $to->toDateString()]))
            ->with([
                'delivery',
                'item',
                'allocations' => fn ($query) => $query->orderBy('warehouse_stock_lot_id'),
                'allocations.stockLot',
            ]);
    }

    /** @return array<string, mixed> */
    private function serialize(WarehouseDeliveryLine $line): array
    {
        $delivery = $line->delivery;
        $lineQuantity = $this->milli($line->quantity);
        $knownQuantity = 0;
        $holdingWeighted = 0;
        $dwellWeighted = 0;
        $maximumWarehouseDwell = null;
        $receivedDates = [];

        $allocations = $line->allocations->map(function (WarehouseAllocation $allocation) use (
            $delivery,
            &$knownQuantity,
            &$holdingWeighted,
            &$dwellWeighted,
            &$maximumWarehouseDwell,
            &$receivedDates,
        ): array {
            $lot = $allocation->stockLot;
            $quantity = $this->milli($allocation->quantity_allocated);
            $holdingDays = $this->days($lot->received_at, $delivery->dispatched_at);
            $dwellDays = $this->days($lot->received_at, $delivery->delivered_at);
            if ($holdingDays !== null && $dwellDays !== null) {
                $knownQuantity += $quantity;
                $holdingWeighted += $quantity * $holdingDays;
                $dwellWeighted += $quantity * $dwellDays;
                $maximumWarehouseDwell = max($maximumWarehouseDwell ?? 0, $dwellDays);
                $receivedDates[] = $lot->received_at?->toDateString();
            }

            return [
                'id' => $allocation->getKey(),
                'stock_lot_id' => $lot->getKey(),
                'po_number' => $lot->po_number,
                'lot_number' => $lot->lot_number,
                'quantity' => (float) $allocation->quantity_allocated,
                'received_at' => $lot->received_at?->toDateString(),
                'received_date_quality' => $lot->received_date_quality->value,
                'warehouse_holding_days' => $holdingDays,
                'warehouse_dwell_days' => $dwellDays,
                'allocation_method' => $allocation->allocation_method->value,
            ];
        })->all();

        $warehouseHolding = $knownQuantity === 0 ? null : round($holdingWeighted / $knownQuantity, 1);
        $warehouseDwell = $knownQuantity === 0 ? null : round($dwellWeighted / $knownQuantity, 1);

        return [
            'id' => $line->getKey(),
            'delivery_id' => $delivery->getKey(),
            'customer_name' => $delivery->customer_name,
            'delivery_reference' => $delivery->delivery_reference,
            'item_id' => $line->warehouse_item_id,
            'sku_number' => $line->item->sku_number,
            'description' => $line->item->description,
            'quantity' => (float) $line->quantity,
            'unit' => $line->unit,
            'dispatched_at' => $delivery->dispatched_at?->toDateString(),
            'delivered_at' => $delivery->delivered_at?->toDateString(),
            'first_received_at' => collect($receivedDates)->filter()->sort()->first(),
            'last_received_at' => collect($receivedDates)->filter()->sort()->last(),
            'warehouse_holding_days' => $warehouseHolding,
            'warehouse_dwell_days' => $warehouseDwell,
            'maximum_warehouse_dwell_days' => $maximumWarehouseDwell,
            'date_coverage_percent' => $lineQuantity === 0 ? 0.0 : round(($knownQuantity / $lineQuantity) * 100, 1),
            'allocations' => $allocations,
        ];
    }

    private function days(?CarbonImmutable $start, ?CarbonImmutable $end): ?int
    {
        if ($start === null || $end === null || $end->lt($start)) {
            return null;
        }

        return (int) floor($start->diffInDays($end));
    }

    private function milli(mixed $quantity): int
    {
        return (int) round(((float) $quantity) * 1000);
    }
}
