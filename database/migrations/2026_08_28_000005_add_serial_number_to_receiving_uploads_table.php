<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->unsignedInteger('serial_number')->nullable()->after('upload_type_id');
            $table->index(['upload_type_id', 'serial_number']);
        });

        // 1. Backfill from Google Sheet Staging logs if available
        if (Schema::hasTable('google_sheet_logs')) {
            $stagedLogs = DB::table('google_sheet_logs')
                ->whereNotNull('synced_receiving_upload_id')
                ->select(['synced_receiving_upload_id', 'serial_number'])
                ->get();

            foreach ($stagedLogs as $log) {
                DB::table('receiving_uploads')
                    ->where('id', $log->synced_receiving_upload_id)
                    ->update(['serial_number' => $log->serial_number]);
            }
        }

        // 2. Backfill any remaining null serial numbers per upload_type_id
        $uploadTypeIds = DB::table('receiving_uploads')
            ->whereNull('serial_number')
            ->distinct()
            ->pluck('upload_type_id');

        foreach ($uploadTypeIds as $typeId) {
            $uploads = DB::table('receiving_uploads')
                ->where('upload_type_id', $typeId)
                ->whereNull('serial_number')
                ->orderBy('id')
                ->pluck('id');

            $currentMax = (int) (DB::table('receiving_uploads')
                ->where('upload_type_id', $typeId)
                ->whereNotNull('serial_number')
                ->max('serial_number') ?? 0);

            foreach ($uploads as $uploadId) {
                $currentMax++;
                DB::table('receiving_uploads')
                    ->where('id', $uploadId)
                    ->update(['serial_number' => $currentMax]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->dropIndex(['upload_type_id', 'serial_number']);
            $table->dropColumn('serial_number');
        });
    }
};
