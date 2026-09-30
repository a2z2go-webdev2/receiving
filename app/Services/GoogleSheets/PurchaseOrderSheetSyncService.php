<?php

namespace App\Services\GoogleSheets;

use App\Enums\PurchaseOrderArrivalStatus;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetSyncRecord;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PurchaseOrderSheetSyncService
{
    private readonly PurchaseOrderLinker $linker;

    public function __construct(
        private readonly GoogleSheetsApiService $apiService,
        private readonly PurchaseOrderSheetParser $parser,
        ?PurchaseOrderLinker $linker = null,
    ) {
        $this->linker = $linker ?? app(PurchaseOrderLinker::class);
    }

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

        $allPoNumbers = array_values(array_unique(array_filter(
            array_column($parsed['orders'], 'po_number_normalized')
        )));

        $existingSheetPos = empty($allPoNumbers) ? collect() : PoExtraction::query()
            ->where('source_type', 'google_sheet')
            ->where('sheet_slug', $config->slug)
            ->whereIn('po_number_normalized', $allPoNumbers)
            ->get()
            ->keyBy('po_number_normalized');

        $existingPdfPos = empty($allPoNumbers) ? collect() : PoExtraction::query()
            ->where('source_type', '!=', 'google_sheet')
            ->whereIn('po_number_normalized', $allPoNumbers)
            ->get()
            ->keyBy('po_number_normalized');

        foreach ($parsed['orders'] as $orderKey => $order) {
            $hasErrors = ! empty($order['validation_errors']);

            if ($hasErrors) {
                $status = 'invalid';
                $invalidCount++;
            } else {
                $existingSheetPo = $existingSheetPos->get($order['po_number_normalized']);

                if ($existingSheetPo === null) {
                    // Check if another PO extraction (e.g. from PDF) already exists
                    $existingPdfPo = $existingPdfPos->get($order['po_number_normalized']);

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

            $targetPoNumbers = array_values(array_unique(array_filter(
                array_column($preview['orders'], 'po_number_normalized')
            )));

            if ($orderKeys !== null) {
                $targetPoNumbers = array_values(array_intersect($targetPoNumbers, $orderKeys));
            }

            $existingPos = empty($targetPoNumbers) ? collect() : PoExtraction::query()
                ->with('items')
                ->where('source_type', 'google_sheet')
                ->where('sheet_slug', $config->slug)
                ->whereIn('po_number_normalized', $targetPoNumbers)
                ->get()
                ->keyBy('po_number_normalized');

            $existingRecords = empty($targetPoNumbers) ? collect() : GoogleSheetSyncRecord::query()
                ->where('sheet_slug', $config->slug)
                ->where('record_type', 'purchase_order')
                ->whereIn('source_key', $targetPoNumbers)
                ->get()
                ->keyBy('source_key');

            foreach ($preview['orders'] as $orderKey => $order) {
                if ($orderKeys !== null && ! in_array($order['po_number_normalized'], $orderKeys, true)) {
                    continue;
                }

                $record = $existingRecords->get($order['po_number_normalized']);

                if ($order['preview_status'] === 'invalid') {
                    if ($record === null) {
                        $record = new GoogleSheetSyncRecord;
                        $record->sheet_slug = $config->slug;
                        $record->record_type = 'purchase_order';
                        $record->source_key = $order['po_number_normalized'];
                    }
                    $record->forceFill([
                        'source_hash' => $order['row_hash'],
                        'raw_data' => $order,
                        'status' => 'invalid',
                        'validation_errors' => $order['validation_errors'] ?: ['Invalid PO structure'],
                    ])->save();
                    $failedCount++;

                    continue;
                }

                if ($order['preview_status'] === 'unchanged') {
                    if ($orderKeys !== null) {
                        $existingPo = $existingPos->get($order['po_number_normalized']);
                        if ($existingPo !== null) {
                            $this->linker->syncPoExtraction($existingPo);
                        }
                    }
                    $skippedCount++;

                    continue;
                }

                /** @var PoExtraction|null $poExtraction */
                $poExtraction = $existingPos->get($order['po_number_normalized']);

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

                // Upsert items preserving existing line identities without N+1 queries
                $existingItems = $poExtraction->relationLoaded('items')
                    ? $poExtraction->items->keyBy('source_line_id')
                    : $poExtraction->items()->get()->keyBy('source_line_id');

                foreach ($order['items'] as $itemData) {
                    /** @var PoExtractionItem|null $item */
                    $item = $existingItems->get($itemData['source_line_id']);

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

                if ($record === null) {
                    $record = new GoogleSheetSyncRecord;
                    $record->sheet_slug = $config->slug;
                    $record->record_type = 'purchase_order';
                    $record->source_key = $order['po_number_normalized'];
                }

                $record->forceFill([
                    'source_hash' => $order['row_hash'],
                    'raw_data' => $order,
                    'status' => 'synced',
                    'validation_errors' => null,
                    'target_type' => PoExtraction::class,
                    'target_id' => $poExtraction->getKey(),
                    'synced_at' => now(),
                ])->save();

                // Automatically cross-check and link any previously uploaded invoices/receipts waiting for this PO across lanes
                $this->linker->syncPoExtraction($poExtraction);

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
        $envPoSheetId = config('services.google.purchase_orders_sheet_id');
        if (empty($envPoSheetId)) {
            $master = GoogleSheetConfig::query()
                ->where('sheet_type', 'purchase_order')
                ->whereNotNull('spreadsheet_id')
                ->where('spreadsheet_id', '!=', '')
                ->first();
            if ($master === null) {
                $master = GoogleSheetConfig::query()
                    ->whereIn('slug', ['purchase_orders', 'purchase-orders', 'po', 'po_master'])
                    ->whereNotNull('spreadsheet_id')
                    ->where('spreadsheet_id', '!=', '')
                    ->first();
            }
            $envPoSheetId = $master?->spreadsheet_id;
        }

        if ($configOrSlug instanceof GoogleSheetConfig) {
            if (! empty($envPoSheetId) && ($configOrSlug->sheet_type !== 'purchase_order' || empty($configOrSlug->spreadsheet_id))) {
                $configOrSlug->update([
                    'spreadsheet_id' => $envPoSheetId,
                    'sheet_type' => 'purchase_order',
                ]);
            }

            return $configOrSlug;
        }

        $config = GoogleSheetConfig::query()->where('slug', $configOrSlug)->first();

        if ($config === null) {
            return GoogleSheetConfig::query()->create([
                'slug' => $configOrSlug,
                'name' => ucfirst(str_replace('_', ' ', $configOrSlug)),
                'sheet_type' => 'purchase_order',
                'spreadsheet_id' => $envPoSheetId,
            ]);
        }

        if (! empty($envPoSheetId) && ($config->sheet_type !== 'purchase_order' || empty($config->spreadsheet_id))) {
            $config->update([
                'spreadsheet_id' => $envPoSheetId,
                'sheet_type' => 'purchase_order',
            ]);
        }

        return $config;
    }

    /**
     * Determine whether a spreadsheet tab represents a Purchase Order sheet.
     * Excludes Sales Orders, delivery receipts, summaries, and other non-PO sheets.
     */
    public function isPurchaseOrderTab(string $tabName): bool
    {
        $lower = strtolower(trim($tabName));

        if (
            str_contains($lower, 'sales order')
            || str_contains($lower, 'sales r')
            || str_contains($lower, 'so ')
            || str_ends_with($lower, ' so')
            || str_contains($lower, 'delivery receipt')
            || str_contains($lower, 'inventory')
            || str_contains($lower, 'summary')
            || str_contains($lower, 'form response')
        ) {
            return false;
        }

        return str_contains($lower, 'purchase order')
            || str_contains($lower, 'purchase_order')
            || str_contains($lower, 'po')
            || $lower === 'pos'
            || str_contains($lower, 'bonita')
            || str_contains($lower, 'a2z')
            || str_contains($lower, 'keysys')
            || str_contains($lower, 'pingcon');
    }

    /**
     * Get candidate tab names for a lane in order of preference.
     *
     * @return array<int, string>
     */
    public function candidateTabNamesForLane(string $laneSlug, ?GoogleSheetConfig $config = null): array
    {
        $normalizedSlug = strtolower(trim($laneSlug));
        $candidates = [];

        if ($config !== null && ! empty($config->tab_name)) {
            $candidates[] = $config->tab_name;
        }

        $defaults = match ($normalizedSlug) {
            'keysys' => [
                'Purchase Orders - KEYSYS',
                'Purchase Orders KEYSYS',
                'KEYSYS - Purchase Orders',
                'KEYSYS',
            ],
            'a2z2go', 'a2z' => [
                'Purchase Orders A2Z',
                'Purchase Orders - A2Z',
                'Purchase Orders - A2Z2GO',
                'Purchase Orders A2Z2GO',
                'A2Z',
            ],
            'bonita' => [
                'Purchase Orders BONITA',
                'Purchase Orders - BONITA',
                'BONITA - Purchase Orders',
                'BONITA',
            ],
            'pingcon' => [
                'Purchase Orders',
                'Purchase Orders - PINGCON',
                'Purchase Orders PINGCON',
                'PINGCON',
            ],
            default => [
                "Purchase Orders - {$laneSlug}",
                "Purchase Orders {$laneSlug}",
                'Purchase Orders',
            ],
        };

        foreach ($defaults as $tab) {
            if (! in_array($tab, $candidates, true)) {
                $candidates[] = $tab;
            }
        }

        return $candidates;
    }

    /**
     * Resolve the appropriate sheet tab name for a configuration.
     *
     * @param  array<int, string>|null  $availableTabs
     */
    public function resolveTabName(GoogleSheetConfig $config, ?array $availableTabs = null): string
    {
        // 1. Explicit configured tab name
        if (! empty($config->tab_name)) {
            return $config->tab_name;
        }

        // 2. If available tabs were supplied, match against them
        if (! empty($availableTabs)) {
            $matched = $this->matchTabFromList($config->slug, $availableTabs);
            if ($matched !== null) {
                return $matched;
            }
        }

        // 3. Fallback to default tab naming conventions
        $candidates = $this->candidateTabNamesForLane($config->slug, $config);

        return $candidates[0] ?? 'Purchase Orders';
    }

    /**
     * Map a tab name back to the corresponding upload type / sheet slug.
     */
    public function resolveSlugFromTabName(string $tabName): string
    {
        if (! $this->isPurchaseOrderTab($tabName)) {
            return Str::slug($tabName);
        }

        $lower = strtolower(trim($tabName));

        if (str_contains($lower, 'a2z')) {
            return 'a2z2go';
        }

        if (str_contains($lower, 'bonita')) {
            return 'bonita';
        }

        if (str_contains($lower, 'keysys')) {
            return 'keysys';
        }

        if (str_contains($lower, 'pingcon')) {
            return 'pingcon';
        }

        // Default "Purchase Orders" tab belongs to Pingcon
        if ($lower === 'purchase orders' || str_contains($lower, 'purchase order')) {
            return 'pingcon';
        }

        return Str::slug($tabName);
    }

    /**
     * Format a cell range qualified with a quoted Google Sheets tab name.
     */
    public function formatRangeWithTab(string $tabName, ?string $range = null): string
    {
        if ($range !== null && str_contains($range, '!')) {
            return $range;
        }

        $cellRange = $range ?: 'A:Z';
        $cleanTab = trim($tabName);
        if ((str_starts_with($cleanTab, "'") && str_ends_with($cleanTab, "'"))
            || (str_starts_with($cleanTab, '"') && str_ends_with($cleanTab, '"'))) {
            $cleanTab = substr($cleanTab, 1, -1);
        }
        $escapedTab = str_replace("'", "''", $cleanTab);

        return "'{$escapedTab}'!{$cellRange}";
    }

    /**
     * Synchronize all purchase order tabs in a spreadsheet.
     *
     * @return array{
     *     spreadsheet_id: string,
     *     total_tabs: int,
     *     synced_tabs: int,
     *     tabs_synced: array<string, array<string, mixed>>,
     *     tab_errors: array<string, string>
     * }
     */
    public function syncAllTabs(
        GoogleSheetConfig|string $configOrSlug,
        string $mode = 'apply',
    ): array {
        $config = $this->resolveConfig($configOrSlug);
        $envPoSheetId = config('services.google.purchase_orders_sheet_id');
        $spreadsheetId = ! empty($envPoSheetId) ? $envPoSheetId : $config->spreadsheet_id;

        if (empty($spreadsheetId)) {
            throw new RuntimeException("Spreadsheet ID is not configured for sheet '{$config->slug}'.");
        }

        $tabs = [];
        try {
            $tabs = $this->apiService->fetchSpreadsheetTabs($spreadsheetId);
        } catch (\Throwable $e) {
            Log::warning("Could not fetch tab list for spreadsheet {$spreadsheetId}: {$e->getMessage()}. Using standard tabs.");
        }

        $poTabs = array_values(array_filter($tabs, fn (string $t) => $this->isPurchaseOrderTab($t)));

        if (empty($poTabs)) {
            if (! empty($tabs)) {
                // If spreadsheet tabs were fetched from the API, use non-excluded tabs
                $poTabs = array_values(array_filter($tabs, function (string $t) {
                    $lower = strtolower(trim($t));

                    return ! (
                        str_contains($lower, 'sales order')
                        || str_contains($lower, 'sales r')
                        || str_contains($lower, 'delivery receipt')
                        || str_contains($lower, 'inventory')
                        || str_contains($lower, 'form response')
                    );
                }));
            }

            if (empty($poTabs)) {
                $masterTab = ! empty($config->tab_name) ? $config->tab_name : 'Purchase Orders';
                $poTabs = array_values(array_unique([
                    $masterTab,
                    'Purchase Orders',
                    'Purchase Orders - KEYSYS',
                    'Purchase Orders KEYSYS',
                    'Purchase Orders BONITA',
                    'Purchase Orders - BONITA',
                    'Purchase Orders A2Z',
                    'Purchase Orders - A2Z',
                ]));
            }
        }

        $results = [];
        $tabErrors = [];
        $syncedCount = 0;

        foreach ($poTabs as $tabName) {
            try {
                $slug = $this->resolveSlugFromTabName($tabName);

                /** @var GoogleSheetConfig $targetConfig */
                $targetConfig = GoogleSheetConfig::query()->firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => ucfirst($slug),
                        'sheet_type' => 'purchase_order',
                        'spreadsheet_id' => $spreadsheetId,
                        'tab_name' => $tabName,
                    ]
                );

                if ($targetConfig->spreadsheet_id !== $spreadsheetId || $targetConfig->tab_name !== $tabName) {
                    $targetConfig->update([
                        'spreadsheet_id' => $spreadsheetId,
                        'tab_name' => $tabName,
                        'sheet_type' => 'purchase_order',
                    ]);
                }

                $tabRange = $this->formatRangeWithTab($tabName);

                if ($mode === 'preview') {
                    $results[$tabName] = $this->preview($targetConfig, $tabRange);
                } else {
                    $results[$tabName] = $this->applySnapshot($targetConfig, range: $tabRange);
                }
                $syncedCount++;
            } catch (\Throwable $e) {
                Log::warning("Could not sync PO sheet tab '{$tabName}': {$e->getMessage()}");
                $tabErrors[$tabName] = $e->getMessage();
                $results[$tabName] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'total_orders' => 0,
                    'applied_count' => 0,
                ];
            }
        }

        if ($syncedCount === 0 && ! empty($tabErrors)) {
            $firstError = reset($tabErrors);
            throw new RuntimeException($firstError);
        }

        return [
            'spreadsheet_id' => $spreadsheetId,
            'total_tabs' => count($poTabs),
            'synced_tabs' => $syncedCount,
            'tabs_synced' => $results,
            'tab_errors' => $tabErrors,
        ];
    }

    /**
     * Synchronize purchase orders for a specific lane directly from Google Sheets.
     *
     * @param  array<int, mixed>|null  $overrideRows
     * @return array{
     *     slug: string,
     *     tab_name: string,
     *     snapshot_hash: string,
     *     applied_count: int,
     *     skipped_count: int,
     *     failed_count: int,
     *     total_orders: int
     * }
     */
    public function syncLane(
        string $laneSlug,
        string $mode = 'apply',
        ?array $overrideRows = null,
        ?array $targetPoNumbers = null,
    ): array {
        $config = $this->resolveConfig($laneSlug);
        $envPoSheetId = config('services.google.purchase_orders_sheet_id');
        $spreadsheetId = ! empty($envPoSheetId) ? $envPoSheetId : $config->spreadsheet_id;

        if (empty($spreadsheetId)) {
            throw new RuntimeException("Spreadsheet ID is not configured for lane '{$laneSlug}'. Set it in Admin Settings or SHEET_ID_PURCHASE_ORDERS in .env.");
        }

        $candidateTabs = $this->candidateTabNamesForLane($laneSlug, $config);
        $tabName = $candidateTabs[0];

        $rows = $overrideRows;
        if ($rows === null) {
            $lastException = null;
            $fetched = false;

            // 1. Try each candidate tab name sequentially
            foreach ($candidateTabs as $candidateTab) {
                try {
                    $targetRange = $this->formatRangeWithTab($candidateTab);
                    $rows = $this->fetchSheetRows($config, $targetRange);
                    $tabName = $candidateTab;
                    $fetched = true;
                    if ($config->tab_name !== $tabName) {
                        $config->update(['tab_name' => $tabName]);
                    }
                    break;
                } catch (\Throwable $e) {
                    $lastException = $e;
                }
            }

            // 2. If candidates failed, try discovering actual tabs from the spreadsheet
            if (! $fetched) {
                try {
                    $tabs = $this->apiService->fetchSpreadsheetTabs($spreadsheetId);
                    $matchedTab = $this->matchTabFromList($laneSlug, $tabs);
                    if ($matchedTab !== null) {
                        $tabName = $matchedTab;
                        $config->update(['tab_name' => $tabName]);
                        $targetRange = $this->formatRangeWithTab($tabName);
                        $rows = $this->fetchSheetRows($config, $targetRange);
                        $fetched = true;
                    }
                } catch (\Throwable) {
                    // Suppress and fall through
                }
            }

            if (! $fetched && $rows === null) {
                throw $lastException ?? new RuntimeException("Could not fetch rows for lane '{$laneSlug}' from Google Sheets.");
            }
        } else {
            $tabName = ! empty($config->tab_name) && $config->tab_name !== 'Purchase Orders'
                ? $config->tab_name
                : ($laneSlug === 'pingcon' ? 'Purchase Orders' : $candidateTabs[0]);

            if ($config->tab_name !== $tabName) {
                $config->update(['tab_name' => $tabName]);
            }
        }

        $targetRange = $this->formatRangeWithTab($tabName);

        if ($mode === 'preview') {
            $result = $this->preview($config, $targetRange, $rows);
        } else {
            $result = $this->applySnapshot($config, range: $targetRange, overrideRows: $rows, orderKeys: $targetPoNumbers);
        }

        return array_merge($result, [
            'slug' => $config->slug,
            'tab_name' => $tabName,
        ]);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function fetchSheetRows(GoogleSheetConfig $config, ?string $range = null): array
    {
        $envPoSheetId = config('services.google.purchase_orders_sheet_id');
        $sheetId = ! empty($envPoSheetId) ? $envPoSheetId : $config->spreadsheet_id;

        if (empty($sheetId)) {
            throw new RuntimeException("Spreadsheet ID is not configured for sheet '{$config->slug}'. Set it in Admin Settings or SHEET_ID_PURCHASE_ORDERS in .env.");
        }

        if ($range !== null && str_contains($range, '!')) {
            $targetRange = $range;
        } else {
            $tabName = $this->resolveTabName($config);
            $targetRange = $this->formatRangeWithTab($tabName, $range);
        }

        return $this->apiService->fetchRange($sheetId, $targetRange);
    }

    /**
     * Match a sheet slug against a list of actual spreadsheet tab titles.
     *
     * @param  array<int, string>  $tabs
     */
    private function matchTabFromList(string $slug, array $tabs): ?string
    {
        $normalizedSlug = strtolower(trim($slug));
        $poTabs = array_values(array_filter($tabs, fn (string $t) => $this->isPurchaseOrderTab($t)));

        foreach ($poTabs as $tab) {
            if (strtolower(trim($tab)) === $normalizedSlug) {
                return $tab;
            }
        }

        $keyword = match ($normalizedSlug) {
            'a2z2go' => 'a2z',
            'bonita' => 'bonita',
            'keysys' => 'keysys',
            'pingcon' => 'pingcon',
            default => $normalizedSlug,
        };

        foreach ($poTabs as $tab) {
            if (str_contains(strtolower($tab), $keyword)) {
                return $tab;
            }
        }

        if ($normalizedSlug === 'pingcon') {
            foreach ($poTabs as $tab) {
                if (strtolower(trim($tab)) === 'purchase orders') {
                    return $tab;
                }
            }
        }

        return null;
    }
}
