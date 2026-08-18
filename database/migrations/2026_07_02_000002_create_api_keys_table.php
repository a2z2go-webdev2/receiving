<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('public_id', 20)->unique();
            $table->string('token_hash', 64);
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->index(['review_status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->dropIndex(['review_status', 'id']);
        });

        Schema::dropIfExists('api_keys');
    }
};
