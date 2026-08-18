<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_extraction_items', function (Blueprint $table): void {
            $table->string('package')->nullable()->after('product_description');
        });
    }

    public function down(): void
    {
        Schema::table('po_extraction_items', function (Blueprint $table): void {
            $table->dropColumn('package');
        });
    }
};
