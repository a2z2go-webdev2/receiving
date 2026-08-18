<?php

use App\Features\Receiving\Services\CorrectedDataMetadata;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->change();
        });

        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->string('invoice_number', 100)->nullable()->after('document_type');
            $table->index(
                ['invoice_number', 'review_status', 'id'],
                'ai_extractions_invoice_lookup_idx',
            );
            $table->index(
                ['document_type', 'review_status', 'id'],
                'ai_extractions_document_lookup_idx',
            );
            $table->index(
                ['receiving_upload_id', 'review_status', 'id'],
                'ai_extractions_serial_lookup_idx',
            );
        });

        DB::table('ai_extractions')
            ->select(['id', 'corrected_json'])
            ->whereNotNull('corrected_json')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $updates = $rows->map(function (object $row): array {
                    $corrected = is_string($row->corrected_json)
                        ? json_decode($row->corrected_json, true)
                        : $row->corrected_json;

                    return [
                        'id' => $row->id,
                        'invoice_number' => CorrectedDataMetadata::invoiceNumber(
                            is_array($corrected) ? $corrected : null,
                        ),
                    ];
                })->all();

                if (DB::getDriverName() === 'pgsql') {
                    $values = implode(', ', array_fill(0, count($updates), '(?::bigint, ?::varchar)'));
                    $bindings = collect($updates)
                        ->flatMap(fn (array $update): array => [
                            $update['id'],
                            $update['invoice_number'],
                        ])
                        ->all();

                    DB::update(
                        "UPDATE ai_extractions AS target
                         SET invoice_number = source.invoice_number
                         FROM (VALUES {$values}) AS source(id, invoice_number)
                         WHERE target.id = source.id",
                        $bindings,
                    );

                    return;
                }

                foreach ($updates as $update) {
                    DB::table('ai_extractions')
                        ->where('id', $update['id'])
                        ->update(['invoice_number' => $update['invoice_number']]);
                }
            });
    }

    public function down(): void
    {
        DB::table('api_keys')->whereNull('expires_at')->update([
            'expires_at' => now()->addYear(),
        ]);

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable(false)->change();
        });

        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->dropIndex('ai_extractions_invoice_lookup_idx');
            $table->dropIndex('ai_extractions_document_lookup_idx');
            $table->dropIndex('ai_extractions_serial_lookup_idx');
            $table->dropColumn('invoice_number');
        });
    }
};
