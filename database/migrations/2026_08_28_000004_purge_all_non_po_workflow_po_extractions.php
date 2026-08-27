<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Find any PoExtraction records belonging to non-purchase_order upload workflows or synthetic placeholders
        $fakePos = DB::table('po_extractions')
            ->leftJoin('receiving_uploads', 'po_extractions.receiving_upload_id', '=', 'receiving_uploads.id')
            ->leftJoin('upload_types', 'receiving_uploads.upload_type_id', '=', 'upload_types.id')
            ->where('upload_types.workflow', '!=', 'purchase_order')
            ->orWhereNull('upload_types.workflow')
            ->orWhere('po_extractions.po_number', 'LIKE', 'PO-SN%')
            ->orWhere('po_extractions.po_number_normalized', 'LIKE', 'POSN%')
            ->orWhere('po_extractions.po_number', 'LIKE', 'PO-%')
            ->select('po_extractions.id')
            ->get();

        if ($fakePos->isNotEmpty()) {
            $fakePoIds = $fakePos->pluck('id')->unique()->all();

            DB::table('purchase_order_item_arrivals')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            DB::table('purchase_order_item_fulfillments')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            DB::table('purchase_order_document_links')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            DB::table('po_extraction_items')
                ->whereIn('po_extraction_id', $fakePoIds)
                ->delete();

            DB::table('po_extractions')
                ->whereIn('id', $fakePoIds)
                ->delete();
        }

        DB::table('purchase_order_item_arrivals')
            ->where('source_key', 'LIKE', 'GSHEET-ARRIVAL-%')
            ->delete();
    }

    public function down(): void
    {
        // No-op
    }
};
