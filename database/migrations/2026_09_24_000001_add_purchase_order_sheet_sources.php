<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_extractions', function (Blueprint $table): void {
            $table->foreignId('ai_extraction_id')->nullable()->change();
            $table->foreignId('receiving_upload_id')->nullable()->change();
            $table->string('source_type', 32)->default('upload')->after('receiving_upload_id')->index();
            $table->string('sheet_slug')->nullable()->after('source_type')->index();
            $table->string('source_row_hash', 64)->nullable()->after('sheet_slug');
            $table->string('source_status')->nullable()->after('arrival_status');
            $table->string('status_normalized', 32)->nullable()->after('source_status')->index();
            $table->text('notes')->nullable()->after('total_amount');
            $table->timestamp('synced_at')->nullable()->after('notes');
        });

        Schema::table('po_extraction_items', function (Blueprint $table): void {
            $table->string('source_line_id')->nullable()->after('sort_order');
            $table->boolean('is_financial_adjustment')->default(false)->after('source_line_id')->index();
            $table->string('source_quantity')->nullable()->after('quantity');
            $table->string('source_unit')->nullable()->after('unit');
        });

        Schema::table('purchase_order_item_fulfillments', function (Blueprint $table): void {
            $table->foreignId('receiving_upload_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_item_fulfillments', function (Blueprint $table): void {
            $table->foreignId('receiving_upload_id')->nullable(false)->change();
        });

        Schema::table('po_extraction_items', function (Blueprint $table): void {
            $table->dropColumn([
                'source_line_id',
                'is_financial_adjustment',
                'source_quantity',
                'source_unit',
            ]);
        });

        Schema::table('po_extractions', function (Blueprint $table): void {
            $table->dropColumn([
                'source_type',
                'sheet_slug',
                'source_row_hash',
                'source_status',
                'status_normalized',
                'notes',
                'synced_at',
            ]);
            $table->foreignId('receiving_upload_id')->nullable(false)->change();
            $table->foreignId('ai_extraction_id')->nullable(false)->change();
        });
    }
};
