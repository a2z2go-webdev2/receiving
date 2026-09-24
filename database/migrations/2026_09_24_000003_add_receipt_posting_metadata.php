<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_item_arrivals', function (Blueprint $table): void {
            $table->string('posting_status', 32)->default('pending')->after('status')->index();
            $table->timestamp('posted_at')->nullable()->after('posting_status');
            $table->foreignId('warehouse_stock_lot_id')
                ->nullable()
                ->after('posted_at')
                ->constrained('warehouse_stock_lots')
                ->nullOnDelete();
        });

        Schema::table('warehouse_stock_lots', function (Blueprint $table): void {
            $table->string('posting_provenance', 32)->default('manual')->after('confirmed_by_user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stock_lots', function (Blueprint $table): void {
            $table->dropColumn('posting_provenance');
        });

        Schema::table('purchase_order_item_arrivals', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_stock_lot_id']);
            $table->dropColumn(['posting_status', 'posted_at', 'warehouse_stock_lot_id']);
        });
    }
};
