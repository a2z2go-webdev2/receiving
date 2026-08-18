<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_extractions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_extraction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('receiving_upload_id')->constrained()->cascadeOnDelete();
            $table->string('po_number')->nullable()->index();
            $table->string('po_reference')->nullable();
            $table->string('po_date')->nullable();
            $table->string('buyer_company')->nullable()->index();
            $table->text('buyer_address')->nullable();
            $table->string('buyer_contact_numbers')->nullable();
            $table->string('vendor_name')->nullable()->index();
            $table->string('contact_person')->nullable();
            $table->string('vendor_email')->nullable();
            $table->string('vendor_mobile')->nullable();
            $table->text('vendor_address')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('subtotal')->nullable();
            $table->string('vat')->nullable();
            $table->string('total_amount')->nullable();
            $table->timestamps();
        });

        Schema::create('po_extraction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('po_extraction_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('item_code')->nullable();
            $table->text('product_description')->nullable();
            $table->string('quantity')->nullable();
            $table->string('unit')->nullable();
            $table->string('unit_price')->nullable();
            $table->string('line_total')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_extraction_items');
        Schema::dropIfExists('po_extractions');
    }
};
