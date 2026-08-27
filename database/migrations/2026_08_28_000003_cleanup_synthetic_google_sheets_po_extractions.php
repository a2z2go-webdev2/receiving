<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Find all fake/synthetic PO extractions generated from Google Sheets SN placeholders
        $fakePos = DB::table('po_extractions')
            ->where('po_number', 'LIKE', 'PO-SN%')
            ->orWhere('po_number_normalized', 'LIKE', 'POSN%')
            ->get();

        if ($fakePos->isNotEmpty()) {
            $fakePoIds = $fakePos->pluck('id')->all();

            // Delete associated arrival records
            DB::table('purchase_order_item_arrivals')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            // Delete associated fulfillment records
            DB::table('purchase_order_item_fulfillments')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            // Delete associated document links
            DB::table('purchase_order_document_links')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            // Delete associated PO extraction items
            DB::table('po_extraction_items')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            // Delete the synthetic PO extractions
            DB::table('po_extractions')
                ->whereIn('id', $fakePoIds)
                ->delete();
        }

        // Also cleanup any orphaned GSHEET-ARRIVAL records
        DB::table('purchase_order_item_arrivals')
            ->where('source_key', 'LIKE', 'GSHEET-ARRIVAL-%')
            ->delete();
    }

    public function down(): void
    {
        // No down migration needed as these were synthetic invalid entries
    }
};
