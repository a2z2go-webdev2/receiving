<?php

namespace App\Console\Commands;

use App\Features\Receiving\Services\PurchaseOrderItemScheduleImporter;
use Illuminate\Console\Command;

class ImportPurchaseOrderItemSchedules extends Command
{
    protected $signature = 'receiving:import-po-items
        {path? : CSV/Markdown file or directory path. Defaults to database/seeders/data/po_item_records.csv}
        {--keep-missing : Keep previously imported rows active when they are absent from this source}';

    protected $description = 'Import scheduled purchase-order item records from CSV, Markdown, or items directory.';

    public function handle(PurchaseOrderItemScheduleImporter $importer): int
    {
        $path = (string) ($this->argument('path') ?: database_path('seeders/data/po_item_records.csv'));

        try {
            $stats = $importer->import(
                $path,
                null,
                ! $this->option('keep-missing'),
            );
        } catch (\Throwable $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Source rows read', (string) $stats['rows']);
        $this->components->twoColumnDetail('Item records', (string) $stats['records']);
        $this->components->twoColumnDetail('Created', (string) $stats['created']);
        $this->components->twoColumnDetail('Updated', (string) $stats['updated']);
        $this->components->twoColumnDetail('Deactivated missing', (string) $stats['deactivated']);
        $this->components->twoColumnDetail('Skipped rows', (string) $stats['skipped']);
        $this->components->success('PO item schedule import completed.');

        return self::SUCCESS;
    }
}
