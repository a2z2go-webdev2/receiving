<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->string('source_slug', 64)->nullable()->after('upload_type_id')->index();
            $table->unsignedInteger('source_serial')->nullable()->after('source_slug')->index();
            $table->boolean('is_historical')->default(false)->after('source_serial')->index();
        });
    }

    public function down(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->dropColumn(['source_slug', 'source_serial', 'is_historical']);
        });
    }
};
