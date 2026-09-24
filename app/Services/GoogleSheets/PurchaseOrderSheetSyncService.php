<?php

namespace App\Services\GoogleSheets;

use App\Enums\PurchaseOrderArrivalStatus;
use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetSyncRecord;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseOrderSheetSyncService
{
    public function __construct(
        private readonly GoogleSheetsApiService $apiService,
        private readonly PurchaseOrderSheetParser $parser,
    ) {}

    /**
     * Preview purchase orders parsed from sheet rows without mutating durable PO extractions.
     *
     * @param  array<int, mixed>|null  $overrideRows
     * @return array{
     *     snapshot_hash: string,
     *     slug: string,
     *     total_orders: int,
     *     new_count: int,
     *     unchanged_count: int,
     *     changed_count: int,
     *     invalid_count: int,
     *     conflict_count: int,
     *     orders: array<string, array<string, mixed>>,
     *     invalid_rows: array<int, mixed>
     * }
     */
    public function preview(
        GoogleSheetConfig|string $configOrSlug,
        ?string $range = null,
        ?array $overrideRows = null,
    ): array {
        $config = $this->resolveConfig($configOrSlug);
        $rows = $overrideRows ?? $this->fetchSheetRows($config, $range);

        $parsed = $this->parser->parse($rows);
        $snapshotHash = hash('sha256', (string) json_encode($parsed['orders']));

        $newCount = 0;
        $unchangedCount = 0;
        $changedCount = 0;
        $invalidCount = 0;
        $conflictCount = 0;

        $ordersSummary = [];

        foreach ($parsed['orders'] as $orderKey => $order) {
            $hasErrors = ! empty($order['validation_errors']);

            if ($hasErrors) {
                $status = 'invalid';
                $invalidCount++;
            } else {
                $existingSheetPo = PoExtraction::query()
                    ->where('source_type', 'google_sheet')
                    ->where('sheet_slug', $config->slug)
                    ->where('po_number_normalized', $order['po_number_normalized'])
                    ->first();

                if ($existingSheetPo === null) {
                    // Check if another PO extraction (e.g. from PDF) already exists
                    $existingPdfPo = PoExtraction::query()
                        ->where('po_number_normalized', $order['po_number_normalized'])
                        ->first();

                    if ($existingPdfPo !== null && $existingPdfPo->vendor_name !== null
                        && ! str_contains(strtolower($existingPdfPo->vendor_name), strtolower(substr($order['supplier'], 0, 4)))) {
                        $status = 'conflict';
                        $conflictCount++;
                    } else {
                        $status = 'new';
                        $newCount++;
                    }
                } else {
                    if ($existingSheetPo->source_row_hash === $order['row_hash']) {
                        $status = 'unchanged';
                        $unchangedCount++;
                    } else {
                        $status = 'changed';
                        $changedCount++;
                    }
                }
            }

            $ordersSummary[$orderKey] = array_merge($order, [
                'preview_status' => $status,
            ]);
        }

        return [
            'snapshot_hash' => $snapshotHash,
            'slug' => $config->slug,
            'total_orders' => count($parsed['orders']),
            'new_count' => $newCount,
            'unchanged_count' => $unchangedCount,
            'changed_count' => $changedCount,
            'invalid_count' => $invalidCount + count($parsed['invalid_rows']),
            'conflict_count' => $conflictCount,
            'orders' => $ordersSummary,
            'invalid_rows' => $parsed['invalid_rows'],
        ];
    }

    /**
     * Apply snapshot of validated purchase orders into durable PoExtraction and PoExtractionItem records.
     *
     * @param  array<int, mixed>|null  $overrideRows
     * @param  array<int, string>|null  $orderKeys
     * @return array{
     *     snapshot_hash: string,
     *     applied_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     total_orders: int
     * }
     */
    public function applySnapshot(
        GoogleSheetConfig|string $configOrSlug,
        ?string $expectedSnapshotHash = null,
        ?string $range = null,
        ?array $overrideRows = null,
        ?array $orderKeys = null,
    ): array {
        $config = $this->resolveConfig($configOrSlug);
        $preview = $this->preview($config, $range, $overrideRows);

        if ($expectedSnapshotHash !== null && $preview['snapshot_hash'] !== $expectedSnapshotHash) {
            throw new RuntimeException("Snapshot hash mismatch. Sheet content has changed since preview (expected: {$expectedSnapshotHash}, actual: {$preview['snapshot_hash']}).");
        }

        return DB::transaction(function () use ($config, $preview, $orderKeys): array {
            $appliedCount = 0;
            $skippedCount = 0;
            $failedCount = 0;

            foreach ($preview['orders'] as $orderKey => $order) {
                if ($orderKeys !== null && ! in_array($order['po_number_normalized'], $orderKeys, true)) {
                    continue;
                }

                if ($order['preview_status'] === 'invalid') {
                    GoogleSheetSyncRecord::query()->updateOrCreate(
                        [
                            'sheet_slug' => $config->slug,
                            'record_type' => 'purchase_order',
                            'source_key' => $order['po_number_normalized'],
                        ],
                        [
                            'source_hash' => $order['row_hash'],
                            'raw_data' => $order,
                            'status' => 'invalid',
                            'validation_errors' => $order['validation_errors'] ?: ['Invalid PO structure'],
                        ]
                    );
                    $failedCount++;

                    continue;
                }

                if ($order['preview_status'] === 'unchanged') {
                    $skippedCount++;

                    continue;
                }

                /** @var PoExtraction|null $poExtraction */
                $poExtraction = PoExtraction::query()
                    ->where('source_type', 'google_sheet')
                    ->where('sheet_slug', $config->slug)
                    ->where('po_number_normalized', $order['po_number_normalized'])
                    ->first();

                if ($poExtraction === null) {
                    $poExtraction = new PoExtraction;
                    $poExtraction->source_type = 'google_sheet';
                    $poExtraction->sheet_slug = $config->slug;
                    $poExtraction->po_number_normalized = $order['po_number_normalized'];
                }

                $poDateValue = $order['po_date_value'] !== null
                    ? CarbonImmutable::parse($order['po_date_value'])
                    : null;

                $poExtraction->forceFill([
                    'po_number' => $order['po_number'],
                    'vendor_name' => $order['supplier'],
                    'source_status' => $order['raw_status'],
                    'status_normalized' => $order['status_normalized'],
                    'arrival_status' => match ($order['status_normalized']) {
                        'received' => PurchaseOrderArrivalStatus::Arrived,
                        default => PurchaseOrderArrivalStatus::Pending,
                    },
                    'po_date' => $order['po_date'],
                    'po_date_value' => $poDateValue,
                    'subtotal' => $order['net_total'],
                    'vat' => $order['vat_total'],
                    'total_amount' => $order['total_amount'],
                    'notes' => $order['notes'],
                    'source_row_hash' => $order['row_hash'],
                    'synced_at' => now(),
                ])->save();

                // Upsert items preserving existing line identities
                foreach ($order['items'] as $itemData) {
                    /** @var PoExtractionItem|null $item */
                    $item = $poExtraction->items()
                        ->where('source_line_id', $itemData['source_line_id'])
                        ->first();

                    if ($item === null) {
                        $item = new PoExtractionItem;
                        $item->po_extraction_id = $poExtraction->getKey();
                        $item->source_line_id = $itemData['source_line_id'];
                    }

                    $item->forceFill([
                        'sort_order' => $itemData['sort_order'],
                        'item_code' => $itemData['item_code'],
                        'product_description' => $itemData['product_description'],
                        'quantity' => $itemData['quantity'],
                        'source_quantity' => $itemData['quantity'],
                        'unit' => $itemData['unit'],
                        'source_unit' => $itemData['unit'],
                        'unit_price' => $itemData['unit_price'],
                        'line_total' => $itemData['line_total'],
                        'is_financial_adjustment' => $itemData['is_financial_adjustment'],
                    ])->save();
                }

                GoogleSheetSyncRecord::query()->updateOrCreate(
                    [
                        'sheet_slug' => $config->slug,
                        'record_type' => 'purchase_order',
                        'source_key' => $order['po_number_normalized'],
                    ],
                    [
                        'source_hash' => $order['row_hash'],
                        'raw_data' => $order,
                        'status' => 'synced',
                        'validation_errors' => null,
                        'target_type' => PoExtraction::class,
                        'target_id' => $poExtraction->getKey(),
                        'synced_at' => now(),
                    ]
                );

                $appliedCount++;
            }

            $config->forceFill([
                'last_snapshot_hash' => $preview['snapshot_hash'],
                'last_snapshot_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            return [
                'snapshot_hash' => $preview['snapshot_hash'],
                'applied_count' => $appliedCount,
                'skipped_count' => $skippedCount,
                'failed_count' => $failedCount,
                'total_orders' => count($preview['orders']),
            ];
        });
    }

    private function resolveConfig(GoogleSheetConfig|string $configOrSlug): GoogleSheetConfig
    {
        if ($configOrSlug instanceof GoogleSheetConfig) {
            return $configOrSlug;
        }

        return GoogleSheetConfig::query()->firstOrCreate(
            ['slug' => $configOrSlug],
            [
                'name' => ucfirst(str_replace('_', ' ', $configOrSlug)),
                'sheet_type' => 'purchase_order',
            ]
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function fetchSheetRows(GoogleSheetConfig $config, ?string $range = null): array
    {
        if (empty($config->spreadsheet_id)) {
            throw new RuntimeException("Spreadsheet ID is not configured for sheet '{$config->slug}'.");
        }

        $targetRange = $range ?? 'A1:Z5000';

        return $this->apiService->fetchRange($config->spreadsheet_id, $targetRange);
    }
}
