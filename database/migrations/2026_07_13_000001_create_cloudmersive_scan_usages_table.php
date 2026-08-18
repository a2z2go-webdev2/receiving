<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudmersive_scan_usages', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start')->unique();
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudmersive_scan_usages');
    }
};
