<?php

namespace App\Console\Commands;

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
                $masterConfig = GoogleSheetConfig::query()
                    ->whereNotNull('spreadsheet_id')
                    ->where('spreadsheet_id', '!=', '')
                    ->first();

                if ($masterConfig === null) {
                    $this->error('No GoogleSheetConfig found with a configured spreadsheet_id.');

                    return Command::FAILURE;
                }

                $result = $syncService->syncAllTabs($masterConfig, $mode);
                $this->info("Completed sync for all {$result['total_tabs']} tabs in spreadsheet: {$result['spreadsheet_id']}");
                foreach ($result['tabs_synced'] as $tab => $data) {
                    $total = $data['total_orders'] ?? 0;
                    $applied = $data['applied_count'] ?? $data['new_count'] ?? 0;
                    $this->line(" - Tab '{$tab}': {$applied}/{$total} orders processed.");
                }

                return Command::SUCCESS;
            }

            $config = GoogleSheetConfig::query()->where('slug', $target)->first();
            if ($config === null) {
                $this->error("Sheet configuration not found for slug '{$target}'.");

                return Command::FAILURE;
            }

            if ($mode === 'preview') {
                $result = $syncService->preview($config, $range);
                $this->info("Preview: {$result['total_orders']} orders ({$result['new_count']} new, {$result['changed_count']} changed, {$result['invalid_count']} invalid).");
            } else {
                $result = $syncService->applySnapshot($config, range: $range);
                $this->info("Apply: {$result['applied_count']} applied, {$result['skipped_count']} skipped, {$result['failed_count']} failed (Total: {$result['total_orders']}).");
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
