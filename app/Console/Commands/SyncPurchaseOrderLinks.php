<?php

namespace App\Console\Commands;

use App\Features\Receiving\Services\PurchaseOrderLinker;
use Illuminate\Console\Command;

class SyncPurchaseOrderLinks extends Command
{
    protected $signature = 'receiving:sync-purchase-order-links';

    protected $description = 'Re-link invoice and receipt extractions to purchase orders and rebuild item arrivals';

    public function handle(PurchaseOrderLinker $linker): int
    {
        $stats = $linker->resyncAll();

        $this->components->info("Processed {$stats['processed']} invoice or receipt extractions; {$stats['linked']} are linked.");

        return self::SUCCESS;
    }
}
