<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('warehouse_deliveries', function (Blueprint $table): void {
            $table->timestamp('dispatched_at')->nullable()->change();
            $table->timestamp('delivered_at')->nullable()->change();
        });

        Schema::table('warehouse_stock_lots', function (Blueprint $table): void {
            $table->timestamp('received_at')->nullable()->change();
        });

        Schema::table('warehouse_progress_events', function (Blueprint $table): void {
            $table->timestamp('event_date')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouse_progress_events', function (Blueprint $table): void {
            $table->date('event_date')->change();
        });

        Schema::table('warehouse_stock_lots', function (Blueprint $table): void {
            $table->date('received_at')->nullable()->change();
        });

        Schema::table('warehouse_deliveries', function (Blueprint $table): void {
            $table->date('delivered_at')->nullable()->change();
            $table->date('dispatched_at')->nullable()->change();
        });
    }
};
