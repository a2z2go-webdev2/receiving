<?php

namespace App\Http\Controllers\Warehouse;

use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrderItemArrival;
use App\Models\PurchaseOrderItemSchedule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseArrivalsController extends Controller
{
    public function __invoke(Request $request, PurchaseOrderDataNormalizer $normalizer): Response
    {
        $search = $request->query('search');

        $pendingQuery = PurchaseOrderItemArrival::query()
            ->awaitingWarehouseConfirmation()
            ->whereNotNull('po_number')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('po_number', 'like', "%{$search}%")
                        ->orWhere('item_description', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%")
                        ->orWhereHas('schedule', function ($q) use ($search) {
                            $q->where('description', 'like', "%{$search}%")
                                ->orWhere('sku_number', 'like', "%{$search}%")
                                ->orWhere('ean_barcode', 'like', "%{$search}%");
                        });
                });
            });

        $mapArrival = function (PurchaseOrderItemArrival $arrival) use ($normalizer): array {
            $schedule = $arrival->purchase_order_item_schedule_id === null ? null : $arrival->schedule;
            $stockLot = $arrival->relationLoaded('stockLot') ? $arrival->stockLot : null;
            $isReceived = $stockLot !== null;

            return [
                'id' => $arrival->getKey(),
                'po_number' => $arrival->po_number,
                'description' => $schedule instanceof PurchaseOrderItemSchedule ? $schedule->description : ($arrival->item_description ?? 'Unidentified item'),
                'sku_number' => $schedule instanceof PurchaseOrderItemSchedule ? $schedule->sku_number : $arrival->item_code,
                'ordered_quantity' => $arrival->ordered_quantity === null ? null : (float) $arrival->ordered_quantity,
                'supplier_delivered_quantity' => (float) $arrival->arrived_quantity,
                'unit' => $schedule instanceof PurchaseOrderItemSchedule ? $schedule->unit : $arrival->unit,
                'po_date' => $arrival->po_date?->toDateString(),
                'supplier_delivery_date' => $arrival->arrival_date?->toDateString(),
                'po_waiting_days' => $normalizer->waitingDays($arrival->po_date, $arrival->arrival_date),
                'is_received' => $isReceived,
                'received_at' => $stockLot?->received_at?->toDateString(),
                'lot_number' => $stockLot?->lot_number,
            ];
        };

        $pendingArrivals = (clone $pendingQuery)
            ->with('schedule')
            ->orderBy('arrival_date')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString()
            ->through($mapArrival);

        // Build PO-grouped data for the "By PO" tab
        $activePoNumbers = (clone $pendingQuery)
            ->pluck('po_number')
            ->unique()
            ->values();

        $allArrivalsForActivePos = PurchaseOrderItemArrival::query()
            ->whereIn('po_number', $activePoNumbers)
            ->with(['schedule', 'stockLot'])
            ->orderBy('po_number')
            ->orderBy('arrival_date')
            ->orderBy('id')
            ->get();

        $pendingPoGroups = $allArrivalsForActivePos
            ->groupBy('po_number')
            ->map(function ($arrivals, $poNumber) use ($mapArrival): array {
                $first = $arrivals->first();
                $mappedItems = $arrivals->map($mapArrival)->values()->all();
                $pendingCount = count(array_filter($mappedItems, fn ($item) => ! $item['is_received']));

                return [
                    'po_number' => $poNumber,
                    'item_count' => count($mappedItems),
                    'pending_item_count' => $pendingCount,
                    'total_supplier_delivered_quantity' => $arrivals->sum(fn ($a) => (float) $a->arrived_quantity),
                    'po_date' => $first->po_date?->toDateString(),
                    'supplier_delivery_date' => $first->arrival_date?->toDateString(),
                    'items' => $mappedItems,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('warehouse/arrivals', [
            'pendingArrivals' => $pendingArrivals,
            'pendingCount' => (clone $pendingQuery)->count(),
            'pendingPoGroups' => $pendingPoGroups,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }
}
