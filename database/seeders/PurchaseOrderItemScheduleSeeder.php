<?php

namespace Database\Seeders;

use App\Features\Receiving\Services\PurchaseOrderItemScheduleImporter;
use Illuminate\Database\Seeder;

class PurchaseOrderItemScheduleSeeder extends Seeder
{
    public function run(PurchaseOrderItemScheduleImporter $importer): void
    {
        $importer->import(
            database_path('seeders/data/po_item_records.csv'),
            deactivateMissing: true,
        );
    }
}
