<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS po_doc_links_active_po_unique');
    }

    public function down(): void
    {
        $hasMultipleActiveArrivals = DB::table('purchase_order_document_links')
            ->select('po_extraction_id')
            ->whereNull('unlinked_at')
            ->groupBy('po_extraction_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleActiveArrivals) {
            throw new RuntimeException(
                'Cannot restore the one-arrival-per-PO constraint while purchase orders have multiple active invoice or receipt links.',
            );
        }

        DB::statement('CREATE UNIQUE INDEX po_doc_links_active_po_unique ON purchase_order_document_links (po_extraction_id) WHERE unlinked_at IS NULL');
    }
};
