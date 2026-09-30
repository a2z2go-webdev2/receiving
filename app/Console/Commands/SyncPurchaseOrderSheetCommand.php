<?php

namespace App\Console\Commands;

use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Models\GoogleSheetConfig;
use App\Services\GoogleSheets\PurchaseOrderSheetSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPurchaseOrderSheetCommand extends Command
{
    protected $signature = 'sheets:sync-po
                            {target=all : The target sheet slug (a2z2go, bonita, keysys, pingcon, or "all" to sync all tabs)}
                            {--mode=apply : Sync mode ("apply" to persist changes, "preview" to dry-run)}
                            {--range= : Optional specific range (e.g. A1:Z500)}';

    protected $description = 'Synchronize Purchase Orders from Google Sheet tabs into the receiving system without requiring triggers';

    public function handle(PurchaseOrderSheetSyncService $syncService): int
    {
        $target = (string) $this->argument('target');
        $mode = (string) ($this->option('mode') ?: 'apply');
        $range = $this->option('range');

        $this->info("Starting Purchase Order sheet synchronization for: {$target} (mode: {$mode})...");

        try {
            if ($target === 'all' || $target === 'all_po') {
                $envSheetId = config('services.google.purchase_orders_sheet_id');
                $masterConfig = null;

                if (! empty($envSheetId)) {
                    $masterConfig = GoogleSheetConfig::query()->firstOrCreate(
                        ['slug' => 'purchase_orders'],
                        [
                            'name' => 'Purchase Orders Master',
                            'sheet_type' => 'purchase_order',
                            'spreadsheet_id' => $envSheetId,
                            'tab_name' => 'Purchase Orders',
                        ]
                    );

                    if ($masterConfig->spreadsheet_id !== $envSheetId || $masterConfig->sheet_type !== 'purchase_order') {
                        $masterConfig->update([
                            'spreadsheet_id' => $envSheetId,
                            'sheet_type' => 'purchase_order',
                        ]);
                    }
                } else {
                    $masterConfig = GoogleSheetConfig::query()
                        ->where('sheet_type', 'purchase_order')
                        ->whereNotNull('spreadsheet_id')
                        ->where('spreadsheet_id', '!=', '')
                        ->first()
                        ?? GoogleSheetConfig::query()
                            ->whereIn('slug', ['purchase_orders', 'purchase-orders', 'po'])
                            ->whereNotNull('spreadsheet_id')
                            ->where('spreadsheet_id', '!=', '')
                            ->first();
                }

                if ($masterConfig === null) {
                    $this->error('No GoogleSheetConfig found with a configured spreadsheet_id and SHEET_ID_PURCHASE_ORDERS is not set.');

                    return Command::FAILURE;
                }

                $result = $syncService->syncAllTabs($masterConfig, $mode);
                if ($mode === 'apply') {
                    app(PurchaseOrderLinker::class)->resyncAll();
                }
                $this->info("Completed sync for all {$result['total_tabs']} tabs in spreadsheet: {$result['spreadsheet_id']}");
                foreach ($result['tabs_synced'] as $tab => $data) {
                    $total = $data['total_orders'] ?? 0;
                    $applied = $data['applied_count'] ?? $data['new_count'] ?? 0;
                    $this->line(" - Tab '{$tab}': {$applied}/{$total} orders processed.");
                }

                return Command::SUCCESS;
            }

            $config = GoogleSheetConfig::query()->where('slug', $target)->first();
            if ($config === null && $envSheetId = config('services.google.purchase_orders_sheet_id')) {
                $config = GoogleSheetConfig::query()->firstOrCreate(
                    ['slug' => $target],
                    [
                        'name' => ucfirst($target),
                        'sheet_type' => 'purchase_order',
                        'spreadsheet_id' => $envSheetId,
                    ]
                );
            }
            if ($config !== null && empty($config->spreadsheet_id) && $envSheetId = config('services.google.purchase_orders_sheet_id')) {
                $config->update(['spreadsheet_id' => $envSheetId]);
            }
            if ($config === null) {
                $this->error("Sheet configuration not found for slug '{$target}'.");

                return Command::FAILURE;
            }

            if ($mode === 'preview') {
                $result = $syncService->preview($config, $range);
                $this->info("Preview: {$result['total_orders']} orders ({$result['new_count']} new, {$result['changed_count']} changed, {$result['invalid_count']} invalid).");
            } else {
                $result = $syncService->applySnapshot($config, range: $range);
                app(PurchaseOrderLinker::class)->resyncAll();
                $this->info("Apply: {$result['applied_count']} applied, {$result['skipped_count']} skipped, {$result['failed_count']} failed (Total: {$result['total_orders']}).");
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
