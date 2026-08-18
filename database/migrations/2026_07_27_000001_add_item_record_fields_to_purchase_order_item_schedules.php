<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_item_schedules', function (Blueprint $table): void {
            $table->unsignedInteger('serial_number')->nullable()->after('id')->index();
            $table->string('ean_barcode')->nullable()->after('sku_number_normalized')->index();
            $table->string('ean_barcode_normalized')->nullable()->after('ean_barcode')->index();
            $table->decimal('package_quantity', 14, 3)->nullable()->after('target_quantity');
            $table->string('package_unit', 50)->nullable()->after('package_quantity');
            $table->decimal('sold_quantity', 14, 3)->nullable()->after('package_unit');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_item_schedules', function (Blueprint $table): void {
            $table->dropColumn([
                'serial_number',
                'ean_barcode',
                'ean_barcode_normalized',
                'package_quantity',
                'package_unit',
                'sold_quantity',
            ]);
        });
    }
};
