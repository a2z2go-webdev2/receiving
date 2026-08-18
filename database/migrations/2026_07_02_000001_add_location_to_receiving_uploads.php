<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('uploader_email');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('location_accuracy_meters', 8, 2)->nullable()->after('longitude');
            $table->timestamp('location_captured_at')->nullable()->after('location_accuracy_meters');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->dropColumn([
                'latitude',
                'longitude',
                'location_accuracy_meters',
                'location_captured_at',
            ]);
        });
    }
};
