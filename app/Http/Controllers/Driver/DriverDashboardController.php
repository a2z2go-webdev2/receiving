<?php

namespace App\Http\Controllers\Driver;

use App\Enums\WarehouseDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\WarehouseDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DriverDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $delivery = null;
        if ($search && trim($search) !== '') {
            $delivery = WarehouseDelivery::query()
                ->with(['lines.item', 'dispatchedBy', 'deliveredBy'])
                ->whereIn('status', [WarehouseDeliveryStatus::Dispatched, WarehouseDeliveryStatus::Delivered])
                ->where(function ($query) use ($search) {
                    $query->where('customer_name', 'LIKE', "%{$search}%")
                        ->orWhere('sales_order', 'LIKE', "%{$search}%")
                        ->orWhere('po', 'LIKE', "%{$search}%");
                })
                ->orderByRaw("CASE WHEN status = 'dispatched' THEN 1 ELSE 2 END")
                ->latest()
                ->first();
        }

        return Inertia::render('driver/dashboard', [
            'search' => $search,
            'delivery' => $delivery,
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $suggestions = WarehouseDelivery::query()
            ->whereIn('status', [WarehouseDeliveryStatus::Dispatched, WarehouseDeliveryStatus::Delivered])
            ->where(function ($q) use ($query) {
                $q->where('customer_name', 'LIKE', "%{$query}%")
                    ->orWhere('sales_order', 'LIKE', "%{$query}%")
                    ->orWhere('po', 'LIKE', "%{$query}%");
            })
            ->orderByRaw("CASE WHEN status = 'dispatched' THEN 1 ELSE 2 END")
            ->latest()
            ->limit(10)
            ->get(['id', 'customer_name', 'sales_order', 'po', 'status', 'delivery_reference'])
            ->map(fn (WarehouseDelivery $d): array => [
                'id' => $d->getKey(),
                'customer_name' => $d->customer_name,
                'sales_order' => $d->sales_order,
                'po' => $d->po,
                'status' => $d->status->value,
                'delivery_reference' => $d->delivery_reference,
            ]);

        return response()->json($suggestions);
    }
}
