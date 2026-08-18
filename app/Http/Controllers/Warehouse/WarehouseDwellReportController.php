<?php

namespace App\Http\Controllers\Warehouse;

use App\Features\Warehouse\Services\WarehouseDwellReport;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseDwellReportController extends Controller
{
    public function __invoke(Request $request, WarehouseDwellReport $report): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : CarbonImmutable::now()->startOfMonth();
        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->endOfDay()
            : CarbonImmutable::now()->endOfMonth();
        $result = $report->build($from, $to);

        return Inertia::render('admin/purchase-orders/reports/warehouse-dwell', [
            ...$result,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'backHref' => '/admin/purchase-orders/reports',
        ]);
    }
}
