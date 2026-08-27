<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\GoogleSheetsDataSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GoogleSheetSyncCommand extends Command
{
    protected $signature = 'sheets:sync
                            {sheet : Slug of the sheet (a2z2go, bonita, keysys, pingcon)}
                            {--range= : Specific serial number or range (e.g. 1-50)}
                            {--limit= : Limit maximum records to sync}
                            {--exclude= : Serials to exclude (e.g. 4, 12-15)}
                            {--sort=ASC : Priority order (ASC or DESC)}';

    protected $description = 'Synchronize Google Sheets upload data by serial number into database';

    public function handle(GoogleSheetsDataSyncService $syncService): int
    {
        $sheet = strtolower((string) $this->argument('sheet'));
        $range = $this->option('range');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $exclude = $this->option('exclude');
        $sort = strtoupper((string) ($this->option('sort') ?: 'ASC'));

        $this->info("Starting Google Sheets sync for {$sheet}...");

        $batchId = (string) Str::uuid();
        $result = $syncService->runBatchSync($sheet, $batchId, $limit, $range, $exclude, $sort);

        $this->info("Sync completed: {$result['successful']} successful, {$result['failed']} failed (Total: {$result['total']}).");

        return $result['failed'] > 0 && $result['successful'] === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
