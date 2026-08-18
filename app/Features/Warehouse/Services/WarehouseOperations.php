<?php

namespace App\Features\Warehouse\Services;

use App\Enums\WarehouseAllocationMethod;
use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseDeliveryStatus;
use App\Enums\WarehouseStockSource;
use App\Models\PurchaseOrderItemArrival;
use App\Models\User;
use App\Models\WarehouseAllocation;
use App\Models\WarehouseDelivery;
use App\Models\WarehouseDeliveryLine;
use App\Models\WarehouseItem;
use App\Models\WarehouseProgressEvent;
use App\Models\WarehouseStockLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseOperations
{
    public function __construct(private readonly WarehouseItemResolver $items) {}

    /** @param array{quantity_received: numeric-string|float|int, lot_number?: string|null, notes?: string|null} $data */
    public function confirmArrival(PurchaseOrderItemArrival $arrival, array $data, User $actor): WarehouseStockLot
    {
        return DB::transaction(function () use ($arrival, $data, $actor): WarehouseStockLot {
            /** @var PurchaseOrderItemArrival $locked */
            $locked = PurchaseOrderItemArrival::query()
                ->with('schedule')
                ->lockForUpdate()
                ->findOrFail($arrival->getKey());

            return $this->confirmSingleArrival($locked, $data, $actor);
        }, 3);
    }

    /**
     * Confirm all pending arrivals for a PO number in a single transaction.
     *
     * Each item uses its own arrived_quantity as the quantity_received.
     * The lot_number and notes apply to all items in the batch.
     *
     * @param  array{po_number: string, lot_number?: string|null, notes?: string|null}  $data
     * @return Collection<int, WarehouseStockLot>
     */
    public function confirmArrivalsByPo(array $data, User $actor): Collection
    {
        return DB::transaction(function () use ($data, $actor): Collection {
            $arrivals = PurchaseOrderItemArrival::query()
                ->with('schedule')
                ->awaitingWarehouseConfirmation()
                ->where('po_number', $data['po_number'])
                ->lockForUpdate()
                ->get();

            if ($arrivals->isEmpty()) {
                throw ValidationException::withMessages([
                    'po_number' => 'No pending arrivals found for this PO number.',
                ]);
            }

            $lots = collect();

            foreach ($arrivals as $arrival) {
                $lots->push($this->confirmSingleArrival($arrival, [
                    'quantity_received' => $arrival->arrived_quantity,
                    'lot_number' => $data['lot_number'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ], $actor));
            }

            return $lots;
        }, 3);
    }

    /**
     * Core logic for confirming a single arrival record.
     *
     * Must be called within an existing DB transaction with the arrival already locked.
     *
     * @param  array{quantity_received: numeric-string|float|int, lot_number?: string|null, notes?: string|null}  $data
     */
    private function confirmSingleArrival(PurchaseOrderItemArrival $locked, array $data, User $actor): WarehouseStockLot
    {
        $sourceKey = $locked->source_key ?? "arrival:{$locked->getKey()}";
        $existing = WarehouseStockLot::query()->where('source_key', $sourceKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $item = $this->items->forArrival($locked);
        $placedAt = now();
        $lot = WarehouseStockLot::query()->create([
            'warehouse_item_id' => $item->getKey(),
            'source_type' => WarehouseStockSource::Arrival,
            'source_key' => $sourceKey,
            'purchase_order_item_arrival_id' => $locked->getKey(),
            'ai_extraction_id' => $locked->ai_extraction_id,
            'receiving_upload_id' => $locked->receiving_upload_id,
            'po_number' => $locked->po_number,
            'lot_number' => $this->nullable($data['lot_number'] ?? null),
            'quantity_received' => $this->decimal($data['quantity_received']),
            'received_at' => $placedAt,
            'received_date_quality' => WarehouseDateQuality::Confirmed,
            'confirmed_by_user_id' => $actor->getKey(),
            'confirmed_at' => $placedAt,
            'notes' => $this->nullable($data['notes'] ?? null),
        ]);

        $this->event('stock_lot', $lot->getKey(), 'pending_arrival', 'in_warehouse', $placedAt, $actor, [
            'source' => WarehouseStockSource::Arrival->value,
            'quantity' => $lot->quantity_received,
        ]);

        return $lot;
    }

    /** @param array{warehouse_item_id?: int|null, sku_number?: string|null, description?: string|null, unit?: string|null, quantity_received: numeric-string|float|int, received_at?: string|null, received_date_quality: string, lot_number?: string|null, notes?: string|null} $data */
    public function addOpeningStock(array $data, User $actor): WarehouseStockLot
    {
        return DB::transaction(function () use ($data, $actor): WarehouseStockLot {
            $existingItem = isset($data['warehouse_item_id'])
                ? WarehouseItem::query()->findOrFail($data['warehouse_item_id'])
                : null;
            $description = $existingItem instanceof WarehouseItem
                ? $existingItem->description
                : (string) ($data['description'] ?? '');
            $item = $this->items->forOpeningStock(
                $existingItem,
                $data['sku_number'] ?? null,
                $description,
                $data['unit'] ?? null,
            );
            $quality = WarehouseDateQuality::from($data['received_date_quality']);
            $eventDate = $data['received_at'] ?? now();

            $lot = WarehouseStockLot::query()->create([
                'warehouse_item_id' => $item->getKey(),
                'source_type' => WarehouseStockSource::OpeningBalance,
                'source_key' => 'opening:'.Str::uuid()->toString(),
                'lot_number' => $this->nullable($data['lot_number'] ?? null),
                'quantity_received' => $this->decimal($data['quantity_received']),
                'received_at' => $quality === WarehouseDateQuality::Unknown ? null : $eventDate,
                'received_date_quality' => $quality,
                'confirmed_by_user_id' => $actor->getKey(),
                'confirmed_at' => now(),
                'notes' => $this->nullable($data['notes'] ?? null),
            ]);

            $this->event('stock_lot', $lot->getKey(), null, 'in_warehouse', $eventDate, $actor, [
                'source' => WarehouseStockSource::OpeningBalance->value,
                'quantity' => $lot->quantity_received,
            ]);

            return $lot;
        }, 3);
    }

    /** @param array{customer_name: string, sales_order?: string|null, po?: string|null, notes?: string|null, lines: array<int, array{warehouse_item_id: int, quantity: numeric-string|float|int}>} $data */
    public function createDelivery(array $data, User $actor): WarehouseDelivery
    {
        return DB::transaction(fn (): WarehouseDelivery => $this->createSingleDelivery($data, $actor), 3);
    }

    /**
     * @param  array<int, array{customer_name: string, sales_order?: string|null, po?: string|null, notes?: string|null, lines: array<int, array{warehouse_item_id: int, quantity: numeric-string|float|int}>}>  $deliveriesData
     * @return Collection<int, WarehouseDelivery>
     */
    public function createBulkDeliveries(array $deliveriesData, User $actor, bool $dispatchImmediately = false): Collection
    {
        return DB::transaction(function () use ($deliveriesData, $actor, $dispatchImmediately): Collection {
            $shipmentRef = 'TRK-'.date('Ymd').'-'.strtoupper(Str::random(4));
            $created = collect();
            foreach ($deliveriesData as $data) {
                $delivery = $this->createSingleDelivery($data, $actor, $shipmentRef);
                if ($dispatchImmediately) {
                    $delivery = $this->dispatchSingleDelivery($delivery, now()->toDateTimeString(), $actor);
                }
                $created->push($delivery);
            }

            return $created;
        }, 3);
    }

    /** @param array{customer_name: string, sales_order?: string|null, po?: string|null, notes?: string|null, lines: array<int, array{warehouse_item_id: int, quantity: numeric-string|float|int}>} $data */
    private function createSingleDelivery(array $data, User $actor, ?string $shipmentRef = null): WarehouseDelivery
    {
        $itemIds = collect($data['lines'])->pluck('warehouse_item_id')->map(fn ($id): int => (int) $id);
        $items = WarehouseItem::query()->whereKey($itemIds)->get()->keyBy('id');
        if ($items->count() !== $itemIds->unique()->count()) {
            throw ValidationException::withMessages(['lines' => 'One or more warehouse items no longer exist.']);
        }

        $shipmentRef = $shipmentRef ?? 'TRK-'.date('Ymd').'-'.strtoupper(Str::random(4));

        $delivery = WarehouseDelivery::query()->create([
            'shipment_reference' => $shipmentRef,
            'customer_name' => trim($data['customer_name']),
            'delivery_reference' => 'DEL-'.date('Ymd').'-'.strtoupper(Str::random(4)),
            'sales_order' => $this->nullable($data['sales_order'] ?? null),
            'po' => $this->nullable($data['po'] ?? null),
            'status' => WarehouseDeliveryStatus::Draft,
            'created_by_user_id' => $actor->getKey(),
            'notes' => $this->nullable($data['notes'] ?? null),
        ]);

        foreach ($data['lines'] as $line) {
            /** @var WarehouseItem $item */
            $item = $items->get((int) $line['warehouse_item_id']);
            $delivery->lines()->create([
                'warehouse_item_id' => $item->getKey(),
                'quantity' => $this->decimal($line['quantity']),
                'unit' => $item->base_unit,
            ]);
        }

        $this->event('delivery', $delivery->getKey(), null, WarehouseDeliveryStatus::Draft->value, now()->toDateString(), $actor);

        return $delivery->load('lines.item');
    }

    /**
     * @param  array<int, array{customer_name: string, sales_order?: string|null, po?: string|null, notes?: string|null, lines: array<int, array{warehouse_item_id: int, quantity: numeric-string|float|int}>}>  $deliveriesData
     * @return Collection<int, WarehouseDelivery>
     */
    public function updateShipmentDeliveries(string $shipmentReference, array $deliveriesData, User $actor): Collection
    {
        return DB::transaction(function () use ($shipmentReference, $deliveriesData, $actor): Collection {
            $deliveries = WarehouseDelivery::query()
                ->where(function ($q) use ($shipmentReference) {
                    $q->where('shipment_reference', $shipmentReference)
                        ->orWhere('delivery_reference', $shipmentReference);
                })
                ->where('status', WarehouseDeliveryStatus::Draft)
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                throw ValidationException::withMessages(['shipment' => 'Shipment not found or already dispatched.']);
            }

            // Delete all existing deliveries for this shipment and recreate updated ones
            foreach ($deliveries as $delivery) {
                $delivery->delete();
            }

            $created = collect();
            foreach ($deliveriesData as $data) {
                $delivery = $this->createSingleDelivery($data, $actor, $shipmentReference);
                $created->push($delivery);
            }

            return $created;
        }, 3);
    }

    public function deleteDraftShipment(string $shipmentReference, User $actor): void
    {
        DB::transaction(function () use ($shipmentReference, $actor): void {
            $deliveries = WarehouseDelivery::query()
                ->where(function ($q) use ($shipmentReference) {
                    $q->where('shipment_reference', $shipmentReference)
                        ->orWhere('delivery_reference', $shipmentReference);
                })
                ->where('status', WarehouseDeliveryStatus::Draft)
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                throw ValidationException::withMessages(['shipment' => 'Shipment not found or already dispatched.']);
            }

            foreach ($deliveries as $delivery) {
                $delivery->delete();
                $this->event('delivery', $delivery->getKey(), WarehouseDeliveryStatus::Draft->value, 'deleted', now(), $actor);
            }
        }, 3);
    }

    /**
     * @return Collection<int, WarehouseDelivery>
     */
    public function dispatchShipment(string $shipmentReference, User $actor): Collection
    {
        return DB::transaction(function () use ($shipmentReference, $actor): Collection {
            $deliveries = WarehouseDelivery::query()
                ->where(function ($q) use ($shipmentReference) {
                    $q->where('shipment_reference', $shipmentReference)
                        ->orWhere('delivery_reference', $shipmentReference);
                })
                ->where('status', WarehouseDeliveryStatus::Draft)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                throw ValidationException::withMessages(['shipment' => 'No draft deliveries found for this shipment.']);
            }

            $dispatched = collect();
            $now = now()->toDateTimeString();
            foreach ($deliveries as $delivery) {
                $dispatched->push($this->dispatchSingleDelivery($delivery, $now, $actor));
            }

            return $dispatched;
        }, 3);
    }

    /** @param array{customer_name: string, sales_order?: string|null, po?: string|null, notes?: string|null, lines: array<int, array{warehouse_item_id: int, quantity: numeric-string|float|int}>} $data */
    public function updateDraftDelivery(WarehouseDelivery $delivery, array $data, User $actor): WarehouseDelivery
    {
        return DB::transaction(function () use ($delivery, $data, $actor): WarehouseDelivery {
            /** @var WarehouseDelivery $locked */
            $locked = WarehouseDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());
            if ($locked->status !== WarehouseDeliveryStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft delivery can be edited.']);
            }

            $itemIds = collect($data['lines'])->pluck('warehouse_item_id')->map(fn ($id): int => (int) $id);
            $items = WarehouseItem::query()->whereKey($itemIds)->get()->keyBy('id');
            if ($items->count() !== $itemIds->unique()->count()) {
                throw ValidationException::withMessages(['lines' => 'One or more warehouse items no longer exist.']);
            }

            $locked->update([
                'customer_name' => trim($data['customer_name']),
                'sales_order' => $this->nullable($data['sales_order'] ?? null),
                'po' => $this->nullable($data['po'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
            ]);

            $locked->lines()->delete();

            foreach ($data['lines'] as $line) {
                /** @var WarehouseItem $item */
                $item = $items->get((int) $line['warehouse_item_id']);
                $locked->lines()->create([
                    'warehouse_item_id' => $item->getKey(),
                    'quantity' => $this->decimal($line['quantity']),
                    'unit' => $item->base_unit,
                ]);
            }

            $this->event('delivery', $locked->getKey(), WarehouseDeliveryStatus::Draft->value, 'updated', now(), $actor);

            return $locked->fresh('lines.item');
        }, 3);
    }

    public function deleteDraftDelivery(WarehouseDelivery $delivery, User $actor): void
    {
        DB::transaction(function () use ($delivery, $actor): void {
            /** @var WarehouseDelivery $locked */
            $locked = WarehouseDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());
            if ($locked->status !== WarehouseDeliveryStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft delivery can be deleted.']);
            }

            $locked->delete();
            $this->event('delivery', $locked->getKey(), WarehouseDeliveryStatus::Draft->value, 'deleted', now(), $actor);
        }, 3);
    }

    public function dispatch(WarehouseDelivery $delivery, string $dispatchedAt, User $actor): WarehouseDelivery
    {
        return DB::transaction(fn (): WarehouseDelivery => $this->dispatchSingleDelivery($delivery, $dispatchedAt, $actor), 3);
    }

    /**
     * @param  array<int, int>  $deliveryIds
     * @return Collection<int, WarehouseDelivery>
     */
    public function dispatchBulk(array $deliveryIds, string $dispatchedAt, User $actor): Collection
    {
        return DB::transaction(function () use ($deliveryIds, $dispatchedAt, $actor): Collection {
            $deliveries = WarehouseDelivery::query()
                ->whereIn('id', $deliveryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($deliveries->count() !== count(array_unique($deliveryIds))) {
                throw ValidationException::withMessages(['delivery_ids' => 'One or more deliveries could not be found.']);
            }

            $dispatched = collect();
            foreach ($deliveries as $delivery) {
                $dispatched->push($this->dispatchSingleDelivery($delivery, $dispatchedAt, $actor));
            }

            return $dispatched;
        }, 3);
    }

    private function dispatchSingleDelivery(WarehouseDelivery $delivery, string $dispatchedAt, User $actor): WarehouseDelivery
    {
        /** @var WarehouseDelivery $locked */
        $locked = WarehouseDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());
        if ($locked->status === WarehouseDeliveryStatus::Dispatched) {
            return $locked->load('lines.allocations');
        }
        if ($locked->status !== WarehouseDeliveryStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only a draft delivery can be dispatched.']);
        }

        $dispatchDate = CarbonImmutable::parse($dispatchedAt);
        $lines = WarehouseDeliveryLine::query()
            ->with('item')
            ->where('warehouse_delivery_id', $locked->getKey())
            ->orderBy('warehouse_item_id')
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            $remaining = $this->milli($line->quantity);
            $lots = WarehouseStockLot::query()
                ->where('warehouse_item_id', $line->warehouse_item_id)
                ->where(function ($query) use ($dispatchDate): void {
                    $query->whereNull('received_at')
                        ->orWhere('received_at', '<=', $dispatchDate);
                })
                ->orderByRaw('CASE WHEN received_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('received_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $allocated = WarehouseAllocation::query()
                ->whereIn('warehouse_stock_lot_id', $lots->pluck('id'))
                ->selectRaw('warehouse_stock_lot_id, SUM(quantity_allocated) AS allocated_quantity')
                ->groupBy('warehouse_stock_lot_id')
                ->pluck('allocated_quantity', 'warehouse_stock_lot_id');

            foreach ($lots as $lot) {
                $available = max(0, $this->milli($lot->quantity_received) - $this->milli($allocated->get($lot->getKey(), 0)));
                if ($available === 0) {
                    continue;
                }

                $take = min($available, $remaining);
                WarehouseAllocation::query()->create([
                    'warehouse_delivery_line_id' => $line->getKey(),
                    'warehouse_stock_lot_id' => $lot->getKey(),
                    'quantity_allocated' => $this->decimalFromMilli($take),
                    'allocation_method' => WarehouseAllocationMethod::Fifo,
                    'allocated_by_user_id' => $actor->getKey(),
                    'allocated_at' => now(),
                ]);
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'stock' => "Insufficient eligible stock for {$line->item->description}. Missing {$this->decimalFromMilli($remaining)} {$line->unit}.",
                ]);
            }
        }

        $locked->forceFill([
            'status' => WarehouseDeliveryStatus::Dispatched,
            'dispatched_at' => $dispatchDate,
            'dispatched_by_user_id' => $actor->getKey(),
        ])->save();
        $this->event('delivery', $locked->getKey(), WarehouseDeliveryStatus::Draft->value, WarehouseDeliveryStatus::Dispatched->value, $dispatchDate, $actor);

        return $locked->load('lines.allocations.stockLot');
    }

    public function deliver(WarehouseDelivery $delivery, string $deliveredAt, ?string $location, User $actor): WarehouseDelivery
    {
        return DB::transaction(function () use ($delivery, $deliveredAt, $location, $actor): WarehouseDelivery {
            /** @var WarehouseDelivery $locked */
            $locked = WarehouseDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());
            if ($locked->status === WarehouseDeliveryStatus::Delivered) {
                return $locked;
            }
            if ($locked->status !== WarehouseDeliveryStatus::Dispatched || $locked->dispatched_at === null) {
                throw ValidationException::withMessages(['status' => 'Dispatch this delivery before marking it delivered.']);
            }

            $deliveryDate = CarbonImmutable::parse($deliveredAt);
            if ($deliveryDate->lt($locked->dispatched_at)) {
                throw ValidationException::withMessages(['delivered_at' => 'Delivery date cannot be before the dispatch date.']);
            }

            $locked->forceFill([
                'status' => WarehouseDeliveryStatus::Delivered,
                'delivered_at' => $deliveryDate,
                'delivery_location' => $location,
                'delivered_by_user_id' => $actor->getKey(),
            ])->save();
            $this->event('delivery', $locked->getKey(), WarehouseDeliveryStatus::Dispatched->value, WarehouseDeliveryStatus::Delivered->value, $deliveryDate, $actor);

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed>|null $metadata */
    private function event(string $type, int $id, ?string $from, string $to, $date, User $actor, ?array $metadata = null): void
    {
        WarehouseProgressEvent::query()->create([
            'aggregate_type' => $type,
            'aggregate_id' => $id,
            'from_status' => $from,
            'to_status' => $to,
            'event_date' => $date,
            'actor_user_id' => $actor->getKey(),
            'metadata' => $metadata,
        ]);
    }

    private function milli(mixed $quantity): int
    {
        return (int) round(((float) $quantity) * 1000);
    }

    private function decimal(mixed $quantity): string
    {
        return $this->decimalFromMilli($this->milli($quantity));
    }

    private function decimalFromMilli(int $quantity): string
    {
        return number_format($quantity / 1000, 3, '.', '');
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
