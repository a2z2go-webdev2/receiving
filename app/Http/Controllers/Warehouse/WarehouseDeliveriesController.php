<?php

namespace App\Http\Controllers\Warehouse;

use App\Features\Warehouse\Services\WarehouseInventoryView;
use App\Http\Controllers\Controller;
use App\Models\WarehouseDelivery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseDeliveriesController extends Controller
{
    public function __invoke(WarehouseInventoryView $inventory, Request $request): Response
    {
        $status = $request->query('status', 'draft');
        $search = $request->query('search');

        // Paginate distinct truck shipment references
        $shipmentsPaginated = WarehouseDelivery::query()
            ->selectRaw('COALESCE(shipment_reference, delivery_reference) as ship_ref, MAX(created_at) as max_created')
            ->where('status', $status)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('delivery_reference', 'like', "%{$search}%")
                        ->orWhere('shipment_reference', 'like', "%{$search}%")
                        ->orWhere('sales_order', 'like', "%{$search}%")
                        ->orWhere('po', 'like', "%{$search}%");
                });
            })
            ->groupBy('ship_ref')
            ->orderByDesc('max_created')
            ->paginate(10)
            ->withQueryString();

        $shipRefKeys = collect($shipmentsPaginated->items())->pluck('ship_ref')->all();

        $deliveriesGrouped = WarehouseDelivery::query()
            ->where('status', $status)
            ->where(function ($q) use ($shipRefKeys) {
                $q->whereIn('shipment_reference', $shipRefKeys)
                    ->orWhereIn('delivery_reference', $shipRefKeys);
            })
            ->with(['lines.item', 'deliveredBy'])
            ->latest()
            ->get()
            ->groupBy(fn (WarehouseDelivery $d): string => $d->shipment_reference ?? $d->delivery_reference ?? (string) $d->id);

        $mappedShipments = collect($shipmentsPaginated->items())->map(function ($row) use ($deliveriesGrouped): array {
            $ref = (string) $row->ship_ref;
            $items = $deliveriesGrouped->get($ref, collect());
            /** @var WarehouseDelivery|null $first */
            $first = $items->first();

            $customers = $items->pluck('customer_name')->unique()->values();
            $customerSummary = $customers->take(2)->join(', ').($customers->count() > 2 ? ' (+'.($customers->count() - 2).' more)' : '');

            $totalItems = $items->sum(fn (WarehouseDelivery $d): int => $d->lines->count());

            return [
                'shipment_reference' => $ref,
                'customer_count' => $customers->count(),
                'customers_summary' => $customerSummary,
                'status' => $first?->status->value ?? 'draft',
                'created_at' => $first?->created_at->toISOString(),
                'dispatched_at' => $first?->dispatched_at?->toDateString(),
                'delivered_at' => $first?->delivered_at?->toDateString(),
                'delivery_location' => $first?->delivery_location,
                'delivered_by_email' => $first?->deliveredBy?->email,
                'total_items_count' => $totalItems,
                'deliveries' => $items->map(fn (WarehouseDelivery $d): array => [
                    'id' => $d->getKey(),
                    'customer_name' => $d->customer_name,
                    'delivery_reference' => $d->delivery_reference,
                    'sales_order' => $d->sales_order,
                    'po' => $d->po,
                    'notes' => $d->notes,
                    'status' => $d->status->value,
                    'lines' => $d->lines->map(fn ($l): array => [
                        'id' => $l->getKey(),
                        'warehouse_item_id' => $l->warehouse_item_id,
                        'description' => $l->item->description,
                        'sku_number' => $l->item->sku_number,
                        'quantity' => (float) $l->quantity,
                        'unit' => $l->unit,
                    ])->all(),
                ])->values()->all(),
            ];
        });

        $paginator = [
            'data' => $mappedShipments->all(),
            'current_page' => $shipmentsPaginated->currentPage(),
            'last_page' => $shipmentsPaginated->lastPage(),
            'prev_page_url' => $shipmentsPaginated->previousPageUrl(),
            'next_page_url' => $shipmentsPaginated->nextPageUrl(),
        ];

        $draftCount = (int) WarehouseDelivery::query()
            ->where('status', 'draft')
            ->selectRaw('COUNT(DISTINCT COALESCE(shipment_reference, delivery_reference)) as cnt')
            ->value('cnt');

        $dispatchedCount = (int) WarehouseDelivery::query()
            ->where('status', 'dispatched')
            ->selectRaw('COUNT(DISTINCT COALESCE(shipment_reference, delivery_reference)) as cnt')
            ->value('cnt');

        return Inertia::render('warehouse/deliveries', [
            'deliveries' => $paginator,
            'deliveryItems' => $inventory->availableItems(),
            'activeTab' => $status,
            'counts' => [
                'draft' => $draftCount,
                'dispatched' => $dispatchedCount,
            ],
            'filters' => [
                'search' => $search,
            ],
        ]);
    }
}
