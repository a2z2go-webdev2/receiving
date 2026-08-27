<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_sheet_configs', function (Blueprint $table): void {
            $table->string('webhook_secret', 64)->nullable()->after('spreadsheet_id');
            $table->boolean('auto_sync_on_webhook')->default(true)->after('webhook_secret');
        });

        // Generate default secure webhook secrets for existing configs
        $configs = DB::table('google_sheet_configs')->get();
        foreach ($configs as $config) {
            DB::table('google_sheet_configs')
                ->where('id', $config->id)
                ->update([
                    'webhook_secret' => 'whsec_'.Str::random(32),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('google_sheet_configs', function (Blueprint $table): void {
            $table->dropColumn(['webhook_secret', 'auto_sync_on_webhook']);
        });
    }
};
