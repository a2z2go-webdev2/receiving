<?php

use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_extractions', function (Blueprint $table): void {
            $table->string('arrival_status', 32)
                ->default(PurchaseOrderArrivalStatus::Pending->value)
                ->after('po_date_value')
                ->index();
        });

        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->string('po_link_status', 64)
                ->default(PurchaseOrderLinkStatus::NotApplicable->value)
                ->after('po_date_filled_from_po_extraction_id')
                ->index();
        });

        Schema::create('purchase_order_document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('po_extraction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_extraction_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->index();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unlinked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlinked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['po_extraction_id', 'unlinked_at'], 'po_doc_links_po_active_index');
            $table->index(['ai_extraction_id', 'unlinked_at'], 'po_doc_links_ai_active_index');
        });

        DB::statement('CREATE UNIQUE INDEX po_doc_links_active_po_unique ON purchase_order_document_links (po_extraction_id) WHERE unlinked_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX po_doc_links_active_ai_unique ON purchase_order_document_links (ai_extraction_id) WHERE unlinked_at IS NULL');

        Schema::create('purchase_order_item_arrivals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_document_link_id')
                ->constrained('purchase_order_document_links')
                ->cascadeOnDelete();
            $table->foreignId('po_extraction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_extraction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receiving_upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('po_extraction_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_item_schedule_id')
                ->nullable()
                ->constrained('purchase_order_item_schedules')
                ->nullOnDelete();
            $table->string('po_number')->nullable();
            $table->date('po_date')->nullable()->index();
            $table->date('arrival_date')->nullable()->index();
            $table->unsignedTinyInteger('po_week')->nullable()->index();
            $table->string('item_code')->nullable();
            $table->text('item_description')->nullable();
            $table->decimal('arrived_quantity', 14, 3)->default(0);
            $table->decimal('ordered_quantity', 14, 3)->nullable();
            $table->decimal('target_quantity', 14, 3)->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('matched_by', 32);
            $table->string('status', 32)->index();
            $table->timestamps();
            $table->index(['purchase_order_item_schedule_id', 'po_date'], 'po_item_arrivals_schedule_date_index');
            $table->index(['po_extraction_id', 'ai_extraction_id'], 'po_item_arrivals_documents_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_item_arrivals');

        DB::statement('DROP INDEX IF EXISTS po_doc_links_active_ai_unique');
        DB::statement('DROP INDEX IF EXISTS po_doc_links_active_po_unique');
        Schema::dropIfExists('purchase_order_document_links');

        Schema::table('ai_extractions', function (Blueprint $table): void {
            $table->dropColumn('po_link_status');
        });

        Schema::table('po_extractions', function (Blueprint $table): void {
            $table->dropColumn('arrival_status');
        });
    }
};
