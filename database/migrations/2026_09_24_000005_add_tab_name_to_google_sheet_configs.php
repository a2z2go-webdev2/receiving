<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_sheet_configs', function (Blueprint $table): void {
            $table->string('tab_name', 100)->nullable()->after('spreadsheet_id');
        });
    }

    public function down(): void
    {
        Schema::table('google_sheet_configs', function (Blueprint $table): void {
            $table->dropColumn('tab_name');
        });
    }
};
