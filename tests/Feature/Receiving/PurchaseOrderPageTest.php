<?php

use App\Enums\AiStatus;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkSource;
use App\Enums\PurchaseOrderLinkStatus;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

test('purchase orders page only displays POs that were linked', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $a2zType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

    // 1. Create an unlinked Google Sheet PO
    $unlinkedPo = PoExtraction::query()->create([
        'po_number' => 'PO-UNLINKED-999',
        'po_number_normalized' => 'POUNLINKED999',
        'vendor_name' => 'Unlinked Vendor',
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_date' => '2026-10-01',
        'po_date_value' => '2026-10-01',
        'arrival_status' => PurchaseOrderArrivalStatus::Pending,
    ]);

    // 2. Create a linked Google Sheet PO
    $linkedPo = PoExtraction::query()->create([
        'po_number' => 'PO-LINKED-100',
        'po_number_normalized' => 'POLINKED100',
        'vendor_name' => 'Acme Supplier Inc.',
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_date' => '2026-09-20',
        'po_date_value' => '2026-09-20',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);

    // Create a linked invoice upload
    $uploader = User::factory()->create();
    $invoiceUpload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $a2zType->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'ai_status' => AiStatus::Extracted,
    ]);
    $invoiceFile = UploadedFile::query()->create([
        'receiving_upload_id' => $invoiceUpload->getKey(),
        'original_file_name' => 'inv.pdf',
        'sanitized_file_name' => 'inv.pdf',
        'stored_file_name' => 'inv.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/inv.pdf',
        'r2_staging_object_key' => "staging/{$invoiceUpload->getKey()}/inv.pdf",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $invoiceExtraction = AiExtraction::query()->create([
        'receiving_upload_id' => $invoiceUpload->getKey(),
        'uploaded_file_id' => $invoiceFile->getKey(),
        'document_type' => 'Invoice',
        'raw_extracted_json' => ['document_type' => 'Invoice'],
        'po_number' => 'PO-LINKED-100',
        'po_number_normalized' => 'POLINKED100',
        'po_link_status' => PurchaseOrderLinkStatus::Linked,
        'ai_status' => AiStatus::Extracted,
    ]);

    // Link PO to the invoice
    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $linkedPo->getKey(),
        'ai_extraction_id' => $invoiceExtraction->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    // Query purchase orders index
    $response = $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/index')
            ->where('pageMode', 'purchase_orders')
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $linkedPo->getKey())
            ->where('uploads.data.0.supplier_name', 'Acme Supplier Inc.')
            ->where('uploads.data.0.po_date', '2026-09-20')
            ->has('uploadTypes')
        );
});

test('purchase orders page source tabs filter correctly', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $a2zType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $pingconType = UploadType::query()->where('slug', 'pingcon')->firstOrFail();

    // Create A2Z linked PO
    $a2zPo = PoExtraction::query()->create([
        'po_number' => 'PO-A2Z-1',
        'po_number_normalized' => 'POA2Z1',
        'vendor_name' => 'A2Z Vendor',
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_date' => '2026-09-01',
        'po_date_value' => '2026-09-01',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);
    $uploader = User::factory()->create();
    $a2zUpload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $a2zType->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'ai_status' => AiStatus::Extracted,
    ]);
    $a2zFile = UploadedFile::query()->create([
        'receiving_upload_id' => $a2zUpload->getKey(),
        'original_file_name' => 'a2z.pdf',
        'sanitized_file_name' => 'a2z.pdf',
        'stored_file_name' => 'a2z.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/a2z.pdf',
        'r2_staging_object_key' => "staging/{$a2zUpload->getKey()}/a2z.pdf",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $a2zExt = AiExtraction::query()->create([
        'receiving_upload_id' => $a2zUpload->getKey(),
        'uploaded_file_id' => $a2zFile->getKey(),
        'document_type' => 'Invoice',
        'raw_extracted_json' => [],
        'po_number' => 'PO-A2Z-1',
        'po_number_normalized' => 'POA2Z1',
        'po_link_status' => PurchaseOrderLinkStatus::Linked,
        'ai_status' => AiStatus::Extracted,
    ]);
    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $a2zPo->getKey(),
        'ai_extraction_id' => $a2zExt->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    // Create PINGCON linked PO
    $pingconPo = PoExtraction::query()->create([
        'po_number' => 'PO-PINGCON-1',
        'po_number_normalized' => 'POPINGCON1',
        'vendor_name' => 'Pingcon Vendor',
        'source_type' => 'google_sheet',
        'sheet_slug' => 'pingcon',
        'po_date' => '2026-09-02',
        'po_date_value' => '2026-09-02',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);
    $pingconUpload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $pingconType->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test2',
        'file_count' => 1,
        'ai_status' => AiStatus::Extracted,
    ]);
    $pingconFile = UploadedFile::query()->create([
        'receiving_upload_id' => $pingconUpload->getKey(),
        'original_file_name' => 'pingcon.pdf',
        'sanitized_file_name' => 'pingcon.pdf',
        'stored_file_name' => 'pingcon.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/pingcon.pdf',
        'r2_staging_object_key' => "staging/{$pingconUpload->getKey()}/pingcon.pdf",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $pingconExt = AiExtraction::query()->create([
        'receiving_upload_id' => $pingconUpload->getKey(),
        'uploaded_file_id' => $pingconFile->getKey(),
        'document_type' => 'Invoice',
        'raw_extracted_json' => [],
        'po_number' => 'PO-PINGCON-1',
        'po_number_normalized' => 'POPINGCON1',
        'po_link_status' => PurchaseOrderLinkStatus::Linked,
        'ai_status' => AiStatus::Extracted,
    ]);
    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $pingconPo->getKey(),
        'ai_extraction_id' => $pingconExt->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    // Test All tab: returns both
    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 2)
        );

    // Test A2Z tab: returns only A2Z
    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.index', ['upload_type_id' => $a2zType->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $a2zPo->getKey())
            ->where('uploads.data.0.supplier_name', 'A2Z Vendor')
        );

    // Test PINGCON tab: returns only PINGCON
    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.index', ['upload_type_id' => $pingconType->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $pingconPo->getKey())
            ->where('uploads.data.0.supplier_name', 'Pingcon Vendor')
        );
});

test('purchase orders detail page renders successfully for a PO', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $po = PoExtraction::query()->create([
        'po_number' => 'PO-SHOW-1',
        'po_number_normalized' => 'POSHOW1',
        'vendor_name' => 'Acme Supplies',
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_date' => '2026-09-20',
        'po_date_value' => '2026-09-20',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);

    $a2zType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $uploader = User::factory()->create();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $a2zType->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'test',
        'file_count' => 1,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'inv.pdf',
        'sanitized_file_name' => 'inv.pdf',
        'stored_file_name' => 'inv.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'inv.pdf',
        'r2_staging_object_key' => 'inv.pdf',
        'original_file_size' => 10,
        'final_file_size' => 10,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
    ]);
    $extraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice',
    ]);

    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $po->getKey(),
        'ai_extraction_id' => $extraction->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    PoExtractionItem::query()->create([
        'po_extraction_id' => $po->getKey(),
        'sort_order' => 1,
        'item_code' => 'ITEM-1',
        'product_description' => 'Test Item 1',
        'quantity' => '10',
        'unit' => 'pcs',
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.show', $po))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/show')
            ->has('purchaseOrder')
            ->where('purchaseOrder.po_number', 'PO-SHOW-1')
            ->has('purchaseOrder.items', 1)
            ->has('purchaseOrder.linked_receipts', 1)
        );
});
