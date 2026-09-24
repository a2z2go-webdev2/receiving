<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_sheet_configs', function (Blueprint $table): void {
            $table->string('sheet_type', 32)->default('receiving')->after('slug')->index();
            $table->string('transition_mode', 32)->default('parallel')->after('auto_sync_on_webhook');
            $table->string('last_snapshot_hash', 64)->nullable()->after('transition_mode');
            $table->timestamp('last_snapshot_at')->nullable()->after('last_snapshot_hash');
        });

        Schema::create('google_sheet_sync_records', function (Blueprint $table): void {
            $table->id();
            $table->string('sheet_slug')->index();
            $table->string('record_type', 32)->index(); // 'purchase_order', 'receiving'
            $table->string('source_key')->index();
            $table->string('source_hash', 64)->nullable();
            $table->json('raw_data')->nullable();
            $table->string('status', 32)->default('staged')->index(); // 'staged', 'synced', 'skipped', 'invalid', 'conflict'
            $table->json('validation_errors')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['sheet_slug', 'record_type', 'source_key'], 'gsheet_sync_records_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheet_sync_records');

        Schema::table('google_sheet_configs', function (Blueprint $table): void {
            $table->dropColumn([
                'sheet_type',
                'transition_mode',
                'last_snapshot_hash',
                'last_snapshot_at',
            ]);
        });
    }
};
