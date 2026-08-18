<?php

namespace App\Http\Controllers\Admin;

use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Models\AiExtraction;
use App\Models\PurchaseOrderItemArrival;
use App\Models\PurchaseOrderItemFulfillment;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\ReceivingUpload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderReportController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderDataNormalizer $normalizer,
        private readonly UploadSerialNumber $serials,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/purchase-orders/reports/index');
    }

    public function orderedItems(Request $request): Response
    {
        [$monthStart, $monthEnd, $month] = $this->monthFilters($request);

        $rows = PurchaseOrderItemFulfillment::query()
            ->with(['schedule', 'poExtraction.aiExtraction', 'poItem'])
            ->whereBetween('po_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->groupBy('purchase_order_item_schedule_id');

        $arrivalsBySchedule = PurchaseOrderItemArrival::query()
            ->with('aiExtraction')
            ->whereBetween('po_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereNotNull('purchase_order_item_schedule_id')
            ->get()
            ->groupBy('purchase_order_item_schedule_id');

        $serialNumbers = $this->serialNumbersForUploadIds(
            $rows->flatten(1)->pluck('receiving_upload_id')
                ->merge($arrivalsBySchedule->flatten(1)->pluck('receiving_upload_id')),
        );

        $rows = $rows
            ->map(function (Collection $fulfillments, int $scheduleId) use ($arrivalsBySchedule, $serialNumbers): array {
                /** @var PurchaseOrderItemFulfillment $first */
                $first = $fulfillments->first();
                $schedule = $first->schedule;
                $orderedQuantity = $fulfillments->sum(fn (PurchaseOrderItemFulfillment $fulfillment): float => (float) $fulfillment->ordered_quantity);
                $arrivals = $arrivalsBySchedule->get($scheduleId, collect());
                $arrivedQuantity = $arrivals->sum(fn (PurchaseOrderItemArrival $arrival): float => (float) $arrival->arrived_quantity);
                $targetQuantity = (float) $schedule->target_quantity;
                $waitingDays = $arrivals
                    ->map(fn (PurchaseOrderItemArrival $arrival): ?int => $this->waitingDays($arrival))
                    ->filter(fn (?int $days): bool => $days !== null)
                    ->values();
                $orderSources = $fulfillments
                    ->map(fn (PurchaseOrderItemFulfillment $fulfillment): string => $this->dataProvenance(
                        $fulfillment->poExtraction->aiExtraction,
                    ));
                $arrivalSources = $arrivals
                    ->map(fn (PurchaseOrderItemArrival $arrival): string => $this->dataProvenance(
                        $arrival->aiExtraction,
                    ));

                return [
                    'schedule_id' => $schedule->getKey(),
                    'serial_number' => $schedule->serial_number,
                    'sku_number' => $schedule->sku_number,
                    'ean_barcode' => $schedule->ean_barcode,
                    'description' => $schedule->description,
                    'target_quantity' => $targetQuantity,
                    'package_quantity' => $schedule->package_quantity !== null ? (float) $schedule->package_quantity : null,
                    'package_unit' => $schedule->package_unit,
                    'ordered_quantity' => $orderedQuantity,
                    'arrived_quantity' => $arrivedQuantity,
                    'remaining_quantity' => max(0, $targetQuantity - $orderedQuantity),
                    'arrival_remaining_quantity' => max(0, $orderedQuantity - $arrivedQuantity),
                    'unit' => $schedule->unit,
                    'expected_week' => null,
                    'schedule_label' => 'Monthly target',
                    'first_arrival_date' => $arrivals
                        ->map(fn (PurchaseOrderItemArrival $arrival): ?string => $arrival->arrival_date?->toDateString())
                        ->filter(fn (?string $date): bool => $date !== null)
                        ->sort()
                        ->first(),
                    'last_arrival_date' => $arrivals
                        ->map(fn (PurchaseOrderItemArrival $arrival): ?string => $arrival->arrival_date?->toDateString())
                        ->filter(fn (?string $date): bool => $date !== null)
                        ->sort()
                        ->last(),
                    'average_waiting_days' => $waitingDays->isEmpty()
                        ? null
                        : round($waitingDays->avg(), 1),
                    'max_waiting_days' => $waitingDays->isEmpty()
                        ? null
                        : $waitingDays->max(),
                    'status' => $this->quantityStatus($orderedQuantity, $targetQuantity),
                    'orders' => $fulfillments
                        ->sortBy('po_date')
                        ->values()
                        ->map(fn (PurchaseOrderItemFulfillment $fulfillment): array => [
                            'id' => $fulfillment->getKey(),
                            'upload_id' => $fulfillment->receiving_upload_id,
                            'serial_number' => $serialNumbers[$fulfillment->receiving_upload_id] ?? $fulfillment->receiving_upload_id,
                            'po_number' => $fulfillment->po_number,
                            'po_date' => $fulfillment->po_date?->toDateString(),
                            'po_week' => $fulfillment->po_week,
                            'quantity' => (float) $fulfillment->ordered_quantity,
                            'unit' => $fulfillment->unit,
                            'matched_by' => $fulfillment->matched_by,
                            'item_description' => $fulfillment->poItem->product_description,
                            'data_source' => $this->dataProvenance($fulfillment->poExtraction->aiExtraction),
                        ])
                        ->all(),
                    'arrivals' => $arrivals
                        ->sortBy([
                            ['arrival_date', 'asc'],
                            ['id', 'asc'],
                        ])
                        ->values()
                        ->map(fn (PurchaseOrderItemArrival $arrival): array => [
                            'id' => $arrival->getKey(),
                            'upload_id' => $arrival->receiving_upload_id,
                            'serial_number' => $serialNumbers[$arrival->receiving_upload_id] ?? $arrival->receiving_upload_id,
                            'po_number' => $arrival->po_number,
                            'po_date' => $arrival->po_date?->toDateString(),
                            'arrival_date' => $arrival->arrival_date?->toDateString(),
                            'waiting_days' => $this->waitingDays($arrival),
                            'po_week' => $arrival->po_week,
                            'quantity' => (float) $arrival->arrived_quantity,
                            'unit' => $arrival->unit,
                            'matched_by' => $arrival->matched_by,
                            'status' => $arrival->status,
                            'item_description' => $arrival->item_description,
                            'data_source' => $this->dataProvenance($arrival->aiExtraction),
                        ])
                        ->all(),
                    'has_unverified_data' => $orderSources->merge($arrivalSources)->contains('unverified'),
                ];
            })
            ->sortBy([
                ['serial_number', 'asc'],
                ['description', 'asc'],
            ])
            ->values()
            ->all();

        return Inertia::render('admin/purchase-orders/reports/ordered-items', [
            'rows' => $rows,
            'filters' => ['month' => $month, 'week' => null],
            'summary' => [
                'item_count' => count($rows),
                'fulfilled_count' => collect($rows)->whereIn('status', ['fulfilled', 'over_target'])->count(),
                'short_count' => collect($rows)->where('status', 'short')->count(),
                'arrived_count' => collect($rows)->filter(fn (array $row): bool => $row['arrived_quantity'] > 0)->count(),
            ],
        ]);
    }

    public function missingItems(Request $request): Response
    {
        [$monthStart, $monthEnd, $month] = $this->monthFilters($request);

        $schedules = PurchaseOrderItemSchedule::query()
            ->with(['fulfillments' => function ($query) use ($monthStart, $monthEnd): void {
                $query->with('poExtraction.aiExtraction')
                    ->whereBetween('po_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            }])
            ->where('is_active', true)
            ->orderByRaw('serial_number ASC NULLS LAST')
            ->orderBy('description')
            ->get();

        $serialNumbers = $this->serialNumbersForUploadIds(
            $schedules->flatMap(fn (PurchaseOrderItemSchedule $schedule) => $schedule->fulfillments)
                ->pluck('receiving_upload_id'),
        );

        $rows = $schedules
            ->map(function (PurchaseOrderItemSchedule $schedule) use ($serialNumbers): ?array {
                $orderedQuantity = $schedule->fulfillments->sum(
                    fn (PurchaseOrderItemFulfillment $fulfillment): float => (float) $fulfillment->ordered_quantity,
                );
                $targetQuantity = (float) $schedule->target_quantity;

                if ($orderedQuantity >= $targetQuantity) {
                    return null;
                }

                return [
                    'schedule_id' => $schedule->getKey(),
                    'serial_number' => $schedule->serial_number,
                    'sku_number' => $schedule->sku_number,
                    'ean_barcode' => $schedule->ean_barcode,
                    'description' => $schedule->description,
                    'target_quantity' => $targetQuantity,
                    'package_quantity' => $schedule->package_quantity !== null ? (float) $schedule->package_quantity : null,
                    'package_unit' => $schedule->package_unit,
                    'ordered_quantity' => $orderedQuantity,
                    'missing_quantity' => max(0, $targetQuantity - $orderedQuantity),
                    'unit' => $schedule->unit,
                    'expected_week' => null,
                    'schedule_label' => 'Monthly target',
                    'status' => $orderedQuantity > 0 ? 'short' : 'not_ordered',
                    'orders' => $schedule->fulfillments
                        ->sortBy('po_date')
                        ->values()
                        ->map(fn (PurchaseOrderItemFulfillment $fulfillment): array => [
                            'id' => $fulfillment->getKey(),
                            'upload_id' => $fulfillment->receiving_upload_id,
                            'serial_number' => $serialNumbers[$fulfillment->receiving_upload_id] ?? $fulfillment->receiving_upload_id,
                            'po_number' => $fulfillment->po_number,
                            'po_date' => $fulfillment->po_date?->toDateString(),
                            'quantity' => (float) $fulfillment->ordered_quantity,
                            'unit' => $fulfillment->unit,
                            'data_source' => $this->dataProvenance($fulfillment->poExtraction->aiExtraction),
                        ])
                        ->all(),
                    'has_unverified_data' => $schedule->fulfillments
                        ->contains(fn (PurchaseOrderItemFulfillment $fulfillment): bool => $this->dataProvenance(
                            $fulfillment->poExtraction->aiExtraction,
                        ) === 'unverified'),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return Inertia::render('admin/purchase-orders/reports/missing-items', [
            'rows' => $rows,
            'filters' => ['month' => $month, 'week' => null],
            'summary' => [
                'item_count' => count($rows),
                'not_ordered_count' => collect($rows)->where('status', 'not_ordered')->count(),
                'short_count' => collect($rows)->where('status', 'short')->count(),
            ],
        ]);
    }

    public function recurringItems(): Response
    {
        $rows = PurchaseOrderItemSchedule::query()
            ->where('is_active', true)
            ->orderByRaw('serial_number ASC NULLS LAST')
            ->orderBy('description')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseOrderItemSchedule $schedule): array => [
                'schedule_id' => $schedule->getKey(),
                'serial_number' => $schedule->serial_number,
                'sku_number' => $schedule->sku_number,
                'ean_barcode' => $schedule->ean_barcode,
                'description' => $schedule->description,
                'target_quantity' => (float) $schedule->target_quantity,
                'package_quantity' => $schedule->package_quantity !== null ? (float) $schedule->package_quantity : null,
                'package_unit' => $schedule->package_unit,
                'sold_quantity' => $schedule->sold_quantity !== null ? (float) $schedule->sold_quantity : null,
                'unit' => $schedule->unit,
                'expected_week' => null,
                'schedule_label' => 'Monthly target',
                'notes' => $schedule->notes,
            ])
            ->all();

        return Inertia::render('admin/purchase-orders/reports/recurring-items', [
            'rows' => $rows,
            'summary' => [
                'item_count' => count($rows),
                'monthly_count' => count($rows),
            ],
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable, string} */
    private function monthFilters(Request $request): array
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = (string) ($validated['month'] ?? CarbonImmutable::now()->format('Y-m'));
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();

        return [$start, $start->endOfMonth(), $month];
    }

    /** @param Collection<int, int> $uploadIds @return array<int, int> */
    private function serialNumbersForUploadIds(Collection $uploadIds): array
    {
        $ids = $uploadIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $uploads = ReceivingUpload::query()
            ->with('uploadType:id,workflow')
            ->whereIn('id', $ids)
            ->get();

        return $this->serials->numbersFor($uploads);
    }

    private function quantityStatus(float $orderedQuantity, float $targetQuantity): string
    {
        if ($targetQuantity <= 0 && $orderedQuantity > 0) {
            return 'fulfilled';
        }

        if ($orderedQuantity > $targetQuantity) {
            return 'over_target';
        }

        if ($orderedQuantity === $targetQuantity) {
            return 'fulfilled';
        }

        return 'short';
    }

    private function waitingDays(PurchaseOrderItemArrival $arrival): ?int
    {
        return $this->normalizer->waitingDays($arrival->po_date, $arrival->arrival_date);
    }

    private function dataProvenance(mixed $extraction): string
    {
        return $extraction instanceof AiExtraction
            ? $extraction->dataProvenance()
            : 'unverified';
    }
}
