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
        Schema::table('warehouse_deliveries', function (Blueprint $table) {
            $table->string('sales_order', 100)->nullable()->after('delivery_reference');
            $table->string('po', 100)->nullable()->after('sales_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouse_deliveries', function (Blueprint $table) {
            $table->dropColumn(['sales_order', 'po']);
        });
    }
};
