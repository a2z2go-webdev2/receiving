<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\WarehouseDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrderItemArrival;
use App\Models\WarehouseDelivery;
use App\Models\WarehouseItem;
use App\Models\WarehouseStockLot;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $pendingArrivals = PurchaseOrderItemArrival::query()
            ->awaitingWarehouseConfirmation()
            ->count();

        return Inertia::render('warehouse/dashboard', [
            'summary' => [
                'pending_arrivals' => $pendingArrivals,
                'inventory_items' => WarehouseItem::query()->whereHas('stockLots')->count(),
                'stock_lots' => WarehouseStockLot::query()->count(),
                'draft_deliveries' => WarehouseDelivery::query()->where('status', WarehouseDeliveryStatus::Draft->value)->count(),
                'dispatched_deliveries' => WarehouseDelivery::query()->where('status', WarehouseDeliveryStatus::Dispatched->value)->count(),
            ],
        ]);
    }
}
