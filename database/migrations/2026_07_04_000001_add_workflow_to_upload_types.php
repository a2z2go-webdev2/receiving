<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_types', function (Blueprint $table): void {
            $table->string('workflow')->default('standard')->after('r2_prefix');
        });

        $timestamp = now();
        DB::table('upload_types')->upsert(
            collect([
                ['name' => 'A2Z2GO', 'slug' => 'a2z2go', 'workflow' => 'standard'],
                ['name' => 'PINGCON', 'slug' => 'pingcon', 'workflow' => 'standard'],
                ['name' => 'BONITA', 'slug' => 'bonita', 'workflow' => 'standard'],
                ['name' => 'KEYSYS INC.', 'slug' => 'keysys', 'workflow' => 'standard'],
                ['name' => 'Purchase Order', 'slug' => 'purchase-order', 'workflow' => 'purchase_order'],
            ])->map(fn (array $type): array => [
                ...$type,
                'r2_prefix' => $type['slug'],
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all(),
            ['slug'],
            ['name', 'r2_prefix', 'workflow', 'updated_at'],
        );
    }

    public function down(): void
    {
        Schema::table('upload_types', function (Blueprint $table): void {
            $table->dropColumn('workflow');
        });
    }
};
