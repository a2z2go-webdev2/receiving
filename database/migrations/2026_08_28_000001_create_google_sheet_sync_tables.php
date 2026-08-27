<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_sheet_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('spreadsheet_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('total_serials')->default(0);
            $table->unsignedInteger('synced_serials')->default(0);
            $table->unsignedInteger('pending_serials')->default(0);
            $table->unsignedInteger('failed_serials')->default(0);
            $table->timestamps();
        });

        Schema::create('google_sheet_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('sheet_slug')->index();
            $table->unsignedInteger('serial_number');
            $table->string('timestamp')->nullable();
            $table->text('drive_folder_link')->nullable();
            $table->unsignedSmallInteger('file_count')->default(1);
            $table->string('email_status')->nullable();
            $table->string('ai_status')->nullable();
            $table->string('review_status')->nullable();
            $table->string('review_token')->nullable();
            $table->string('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('review_token_created_at')->nullable();
            $table->string('review_expires_at')->nullable();
            $table->string('uploader_location')->nullable();
            $table->boolean('is_synced_to_db')->default(false)->index();
            $table->foreignId('synced_receiving_upload_id')->nullable()->constrained('receiving_uploads')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['sheet_slug', 'serial_number']);
        });

        Schema::create('google_sheet_files', function (Blueprint $table): void {
            $table->id();
            $table->string('sheet_slug')->index();
            $table->unsignedInteger('serial_number');
            $table->string('file_no')->nullable();
            $table->string('file_name');
            $table->string('file_id')->nullable();
            $table->text('file_url')->nullable();
            $table->string('mime_type')->default('image/jpeg');
            $table->text('r2_url')->nullable();
            $table->unsignedInteger('row_index')->nullable();
            $table->timestamps();

            $table->index(['sheet_slug', 'serial_number']);
            $table->index(['sheet_slug', 'file_id']);
        });

        Schema::create('google_sheet_extractions', function (Blueprint $table): void {
            $table->id();
            $table->string('sheet_slug')->index();
            $table->unsignedInteger('serial_number');
            $table->string('ai_status')->nullable();
            $table->longText('raw_ai_json')->nullable();
            $table->longText('corrected_json')->nullable();
            $table->string('extracted_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['sheet_slug', 'serial_number']);
        });

        Schema::create('google_sheet_sync_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('sheet_slug')->index();
            $table->string('batch_id')->unique();
            $table->string('status')->default('running')->index();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('successful_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('current_serial')->nullable();
            $table->string('current_status_text')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('logs')->nullable();
            $table->timestamps();
        });

        // Seed default 4 sheet configs
        $defaults = [
            ['slug' => 'a2z2go', 'name' => 'A2Z2GO', 'spreadsheet_id' => env('SHEET_ID_A2Z2GO', '')],
            ['slug' => 'bonita', 'name' => 'BONITA', 'spreadsheet_id' => env('SHEET_ID_BONITA', '')],
            ['slug' => 'keysys', 'name' => 'KEYSYS INC.', 'spreadsheet_id' => env('SHEET_ID_KEYSYS', '')],
            ['slug' => 'pingcon', 'name' => 'PINGCON', 'spreadsheet_id' => env('SHEET_ID_PINGCON', '')],
        ];

        foreach ($defaults as $d) {
            DB::table('google_sheet_configs')->insert([
                'slug' => $d['slug'],
                'name' => $d['name'],
                'spreadsheet_id' => $d['spreadsheet_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheet_sync_jobs');
        Schema::dropIfExists('google_sheet_extractions');
        Schema::dropIfExists('google_sheet_files');
        Schema::dropIfExists('google_sheet_logs');
        Schema::dropIfExists('google_sheet_configs');
    }
};
