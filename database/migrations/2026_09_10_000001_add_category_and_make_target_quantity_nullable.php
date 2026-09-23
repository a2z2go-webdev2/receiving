<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_item_schedules', function (Blueprint $table): void {
            $table->decimal('target_quantity', 14, 3)->nullable()->change();
            $table->string('category', 50)->default('non_food')->after('unit')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_item_schedules', function (Blueprint $table): void {
            $table->dropColumn('category');
            $table->decimal('target_quantity', 14, 3)->nullable(false)->change();
        });
    }
};
