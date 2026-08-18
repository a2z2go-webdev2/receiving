<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_extractions', function (Blueprint $table): void {
            $table->string('po_number_normalized')->nullable()->after('po_number')->index();
            $table->date('po_date_value')->nullable()->after('po_date')->index();
        });

        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->string('po_number')->nullable()->after('invoice_number')->index();
            $table->string('po_number_normalized')->nullable()->after('po_number')->index();
            $table->string('po_date')->nullable()->after('po_number_normalized');
            $table->foreignId('po_date_filled_from_po_extraction_id')
                ->nullable()
                ->after('po_date')
                ->constrained('po_extractions')
                ->nullOnDelete();
        });

        Schema::create('purchase_order_item_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('sku_number')->nullable();
            $table->string('sku_number_normalized')->nullable()->index();
            $table->text('description');
            $table->string('description_normalized', 500)->index();
            $table->decimal('target_quantity', 14, 3);
            $table->string('unit', 50)->nullable();
            $table->unsignedTinyInteger('expected_week')->nullable()->index();
            $table->boolean('is_special_order')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->string('source')->default('manual')->index();
            $table->string('source_key', 128)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['expected_week', 'is_active'], 'po_item_schedules_week_active_index');
            $table->unique(['source', 'source_key'], 'po_item_schedules_source_key_unique');
        });

        Schema::create('purchase_order_item_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_item_schedule_id')
                ->constrained('purchase_order_item_schedules')
                ->cascadeOnDelete();
            $table->foreignId('po_extraction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('po_extraction_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receiving_upload_id')->constrained()->cascadeOnDelete();
            $table->string('po_number')->nullable();
            $table->date('po_date')->nullable()->index();
            $table->unsignedTinyInteger('po_week')->nullable()->index();
            $table->decimal('ordered_quantity', 14, 3)->default(0);
            $table->string('unit', 50)->nullable();
            $table->string('matched_by', 32);
            $table->timestamps();
            $table->unique(
                ['purchase_order_item_schedule_id', 'po_extraction_item_id'],
                'po_item_fulfillments_schedule_item_unique'
            );
            $table->index(['po_date', 'po_week'], 'po_item_fulfillments_date_week_index');
        });

        $this->backfillPoExtractionMetadata();
        $this->backfillAiExtractionMetadata();
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_item_fulfillments');
        Schema::dropIfExists('purchase_order_item_schedules');

        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('po_date_filled_from_po_extraction_id');
            $table->dropColumn(['po_number', 'po_number_normalized', 'po_date']);
        });

        Schema::table('po_extractions', function (Blueprint $table): void {
            $table->dropColumn(['po_number_normalized', 'po_date_value']);
        });
    }

    private function backfillPoExtractionMetadata(): void
    {
        DB::table('po_extractions')
            ->select(['id', 'po_number', 'po_date'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('po_extractions')
                        ->where('id', $row->id)
                        ->update([
                            'po_number_normalized' => $this->normalizeIdentifier($row->po_number),
                            'po_date_value' => $this->parseDate($row->po_date),
                        ]);
                }
            });
    }

    private function backfillAiExtractionMetadata(): void
    {
        DB::table('ai_extractions')
            ->select(['id', 'raw_extracted_json', 'corrected_json'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $data = $this->json($row->corrected_json) ?? $this->json($row->raw_extracted_json) ?? [];
                    $poNumber = $this->fieldValue($data, ['PO Number']);
                    $poDate = $this->fieldValue($data, ['PO Date']);

                    DB::table('ai_extractions')
                        ->where('id', $row->id)
                        ->update([
                            'po_number' => $poNumber,
                            'po_number_normalized' => $this->normalizeIdentifier($poNumber),
                            'po_date' => $poDate,
                        ]);
                }
            });
    }

    /** @return array<string, mixed>|null */
    private function json(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    private function fieldValue(array $data, array $labels): ?string
    {
        $wanted = array_map(fn (string $label): string => $this->fieldKey($label), $labels);
        $fields = $data['fields'] ?? null;
        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (! is_array($field) || ! in_array($this->fieldKey((string) ($field['label'] ?? '')), $wanted, true)) {
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));

            return $this->meaningful($value) ? $value : null;
        }

        return null;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (! $this->meaningful($value)) {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/i', '', strtolower($value)) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (! $this->meaningful($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function fieldKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', strtolower($value)) ?? '';
    }

    private function meaningful(string $value): bool
    {
        return $value !== '' && strtolower($value) !== '[see image]';
    }
};
