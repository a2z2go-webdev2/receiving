<?php

namespace App\Jobs;

use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetSyncJob;
use App\Services\GoogleSheets\PurchaseOrderSheetSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SyncPurchaseOrderSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        public string|int $configOrSlug,
        public ?string $range = null,
        public string $mode = 'apply',
    ) {}

    public function handle(PurchaseOrderSheetSyncService $syncService): void
    {
        if ($this->configOrSlug === 'all' || $this->configOrSlug === 'all_po') {
            $masterConfig = GoogleSheetConfig::query()
                ->whereNotNull('spreadsheet_id')
                ->where('spreadsheet_id', '!=', '')
                ->first();

            if ($masterConfig === null) {
                throw new RuntimeException('No configured Google Sheet found to sync purchase order tabs.');
            }

            $batchId = (string) Str::uuid();
            $syncJob = GoogleSheetSyncJob::query()->create([
                'sheet_slug' => 'all',
                'batch_id' => $batchId,
                'status' => 'processing',
                'started_at' => CarbonImmutable::now(),
                'current_status_text' => "Starting {$this->mode} synchronization for all tabs...",
            ]);

            try {
                $allResults = $syncService->syncAllTabs($masterConfig, $this->mode);
                $totalTabs = $allResults['total_tabs'];
                $syncJob->update([
                    'status' => 'completed',
                    'completed_at' => CarbonImmutable::now(),
                    'total_items' => $totalTabs,
                    'processed_items' => $totalTabs,
                    'successful_items' => $totalTabs,
                    'failed_items' => 0,
                    'current_status_text' => "All {$totalTabs} purchase order tabs synchronized successfully.",
                    'logs' => $allResults,
                ]);
            } catch (Throwable $e) {
                Log::error("SyncPurchaseOrderSheet all tabs failed: {$e->getMessage()}", [
                    'exception' => $e,
                ]);

                $syncJob->update([
                    'status' => 'failed',
                    'completed_at' => CarbonImmutable::now(),
                    'current_status_text' => "Failed: {$e->getMessage()}",
                ]);

                throw $e;
            }

            return;
        }

        $config = is_numeric($this->configOrSlug)
            ? GoogleSheetConfig::query()->find($this->configOrSlug)
            : GoogleSheetConfig::query()->where('slug', $this->configOrSlug)->first();

        $slug = $config instanceof GoogleSheetConfig ? $config->slug : (string) $this->configOrSlug;

        $batchId = (string) Str::uuid();
        $syncJob = GoogleSheetSyncJob::query()->create([
            'sheet_slug' => $slug,
            'batch_id' => $batchId,
            'status' => 'processing',
            'started_at' => CarbonImmutable::now(),
            'current_status_text' => "Starting {$this->mode} synchronization for {$slug}...",
        ]);

        try {
            if ($this->mode === 'preview') {
                $result = $syncService->preview($config ?? $slug, $this->range);
                $syncJob->update([
                    'status' => 'completed',
                    'completed_at' => CarbonImmutable::now(),
                    'total_items' => $result['total_orders'],
                    'processed_items' => $result['total_orders'],
                    'successful_items' => $result['new_count'] + $result['unchanged_count'] + $result['changed_count'],
                    'failed_items' => $result['invalid_count'] + $result['conflict_count'],
                    'current_status_text' => "Preview completed: {$result['total_orders']} orders evaluated.",
                    'logs' => $result,
                ]);
            } else {
                $result = $syncService->applySnapshot($config ?? $slug, range: $this->range);
                $syncJob->update([
                    'status' => 'completed',
                    'completed_at' => CarbonImmutable::now(),
                    'total_items' => $result['total_orders'],
                    'processed_items' => $result['total_orders'],
                    'successful_items' => $result['applied_count'] + $result['skipped_count'],
                    'failed_items' => $result['failed_count'],
                    'current_status_text' => "Apply completed: {$result['applied_count']} applied, {$result['skipped_count']} skipped, {$result['failed_count']} failed.",
                    'logs' => $result,
                ]);
            }
        } catch (Throwable $e) {
            Log::error("SyncPurchaseOrderSheet failed for {$slug}: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            $syncJob->update([
                'status' => 'failed',
                'completed_at' => CarbonImmutable::now(),
                'current_status_text' => "Failed: {$e->getMessage()}",
            ]);

            throw $e;
        }
    }
}
