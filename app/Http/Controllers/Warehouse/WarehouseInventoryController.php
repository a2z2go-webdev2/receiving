<?php

namespace App\Http\Controllers\Warehouse;

use App\Features\Warehouse\Services\WarehouseInventoryView;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseInventoryController extends Controller
{
    public function __invoke(Request $request, WarehouseInventoryView $inventory): Response
    {
        $search = $request->query('search');

        return Inertia::render('warehouse/inventory', [
            'inventory' => $inventory->inventoryPage($search),
            'warehouseItems' => $inventory->itemOptions(),
            'filters' => [
                'search' => $search,
            ],
        ]);
    }
}
