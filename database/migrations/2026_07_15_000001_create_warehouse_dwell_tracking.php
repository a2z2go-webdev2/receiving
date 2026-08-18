<?php

use App\Enums\WarehouseAllocationMethod;
use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseDeliveryStatus;
use App\Enums\WarehouseStockSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_item_arrivals', function (Blueprint $table): void {
            $table->string('source_key', 160)->nullable()->after('id');
        });

        $positions = [];
        DB::table('purchase_order_item_arrivals')
            ->select(['id', 'ai_extraction_id'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$positions): void {
                foreach ($rows as $row) {
                    $aiExtractionId = (int) $row->ai_extraction_id;
                    $line = $positions[$aiExtractionId] ?? 0;
                    $positions[$aiExtractionId] = $line + 1;

                    DB::table('purchase_order_item_arrivals')
                        ->where('id', $row->id)
                        ->update(['source_key' => "ai:{$aiExtractionId}:line:{$line}"]);
                }
            });

        Schema::table('purchase_order_item_arrivals', function (Blueprint $table): void {
            $table->unique('source_key', 'po_item_arrivals_source_key_unique');
        });

        Schema::create('warehouse_items', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_key', 160)->unique();
            $table->string('sku_number')->nullable();
            $table->string('sku_number_normalized')->nullable()->index();
            $table->text('description');
            $table->string('description_normalized', 500)->index();
            $table->string('base_unit', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 32)->default(WarehouseStockSource::Arrival->value)->index();
            $table->string('source_key', 160)->unique();
            $table->foreignId('purchase_order_item_arrival_id')
                ->nullable()
                ->constrained('purchase_order_item_arrivals')
                ->nullOnDelete();
            $table->foreignId('ai_extraction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('receiving_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->string('po_number')->nullable()->index();
            $table->string('lot_number', 100)->nullable()->index();
            $table->decimal('quantity_received', 14, 3);
            $table->date('received_at')->nullable()->index();
            $table->string('received_date_quality', 24)->default(WarehouseDateQuality::Confirmed->value)->index();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['warehouse_item_id', 'received_at', 'id'], 'warehouse_lots_fifo_index');
        });

        Schema::create('warehouse_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_name')->index();
            $table->string('delivery_reference', 100)->nullable()->index();
            $table->string('status', 24)->default(WarehouseDeliveryStatus::Draft->value)->index();
            $table->date('dispatched_at')->nullable()->index();
            $table->date('delivered_at')->nullable()->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'warehouse_deliveries_status_created_index');
        });

        Schema::create('warehouse_delivery_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 50)->nullable();
            $table->timestamps();
            $table->unique(
                ['warehouse_delivery_id', 'warehouse_item_id'],
                'warehouse_delivery_lines_delivery_item_unique',
            );
            $table->index(['warehouse_item_id', 'warehouse_delivery_id'], 'warehouse_delivery_lines_item_index');
        });

        Schema::create('warehouse_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_delivery_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_stock_lot_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_allocated', 14, 3);
            $table->string('allocation_method', 24)->default(WarehouseAllocationMethod::Fifo->value);
            $table->foreignId('allocated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamps();
            $table->unique(
                ['warehouse_delivery_line_id', 'warehouse_stock_lot_id'],
                'warehouse_allocations_line_lot_unique',
            );
            $table->index(['warehouse_stock_lot_id', 'warehouse_delivery_line_id'], 'warehouse_allocations_lot_index');
        });

        Schema::create('warehouse_progress_events', function (Blueprint $table): void {
            $table->id();
            $table->string('aggregate_type', 32);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->date('event_date');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(
                ['aggregate_type', 'aggregate_id', 'created_at'],
                'warehouse_progress_events_aggregate_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE warehouse_stock_lots ADD CONSTRAINT warehouse_stock_lots_quantity_check CHECK (quantity_received > 0)');
            DB::statement("ALTER TABLE warehouse_stock_lots ADD CONSTRAINT warehouse_stock_lots_source_check CHECK (source_type IN ('arrival', 'opening_balance'))");
            DB::statement("ALTER TABLE warehouse_stock_lots ADD CONSTRAINT warehouse_stock_lots_date_quality_check CHECK ((received_date_quality = 'unknown' AND received_at IS NULL) OR (received_date_quality IN ('confirmed', 'estimated') AND received_at IS NOT NULL))");
            DB::statement('ALTER TABLE warehouse_delivery_lines ADD CONSTRAINT warehouse_delivery_lines_quantity_check CHECK (quantity > 0)');
            DB::statement('ALTER TABLE warehouse_allocations ADD CONSTRAINT warehouse_allocations_quantity_check CHECK (quantity_allocated > 0)');
            DB::statement("ALTER TABLE warehouse_allocations ADD CONSTRAINT warehouse_allocations_method_check CHECK (allocation_method = 'fifo')");
            DB::statement("ALTER TABLE warehouse_deliveries ADD CONSTRAINT warehouse_delivery_status_dates_check CHECK ((status = 'draft' AND dispatched_at IS NULL AND delivered_at IS NULL) OR (status = 'dispatched' AND dispatched_at IS NOT NULL AND delivered_at IS NULL) OR (status = 'delivered' AND dispatched_at IS NOT NULL AND delivered_at IS NOT NULL AND delivered_at >= dispatched_at))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_progress_events');
        Schema::dropIfExists('warehouse_allocations');
        Schema::dropIfExists('warehouse_delivery_lines');
        Schema::dropIfExists('warehouse_deliveries');
        Schema::dropIfExists('warehouse_stock_lots');
        Schema::dropIfExists('warehouse_items');

        Schema::table('purchase_order_item_arrivals', function (Blueprint $table): void {
            $table->dropUnique('po_item_arrivals_source_key_unique');
            $table->dropColumn('source_key');
        });
    }
};
