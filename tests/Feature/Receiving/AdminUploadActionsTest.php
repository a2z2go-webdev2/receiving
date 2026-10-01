<?php

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Jobs\StartAiExtraction;
use App\Jobs\SyncPurchaseOrderSheet;
use App\Mail\ReceivingReviewReady;
use App\Mail\ReceivingUploadReceived;
use App\Models\AiExtraction;
use App\Models\GoogleSheetConfig;
use App\Models\PoExtraction;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('requires the admin otp grant on admin upload and file routes', function (): void {
    [$admin, $upload, $file] = adminUploadActionFixture();

    $this->actingAs($admin)
        ->get(route('admin.uploads.show', $upload))
        ->assertRedirect(route('admin.otp.show'));
    $this->postJson(route('receiving.files.url', $file))->assertForbidden();

    $this->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.show', $upload))
        ->assertOk();
});

it('shows purchase order details with the POSN serial format', function (): void {
    [$admin, $upload] = adminUploadActionFixture();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $upload->forceFill(['upload_type_id' => $purchaseOrderType->getKey()])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.show', $upload))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('upload.serial_prefix', 'POSN')
            ->where('upload.serial_number', 1));
});

it('does not expose upload details or file contents to the uploader', function (): void {
    [$admin, $upload, $file] = adminUploadActionFixture();
    $uploader = $upload->uploader;

    $this->actingAs($uploader)
        ->get("/receiving/uploads/{$upload->getKey()}")
        ->assertNotFound();
    $this->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->postJson(route('receiving.files.url', $file))
        ->assertForbidden();
});

it('shows corrected data in admin details only after verification', function (): void {
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    $session = ['admin.otp_verified_at' => now()->getTimestamp()];

    $this->actingAs($admin)
        ->withSession($session)
        ->get(route('admin.uploads.show', $upload))
        ->assertInertia(fn ($page) => $page
            ->missing('upload.processing_status')
            ->missing('upload.email_status')
            ->where('upload.review_email_status', EmailStatus::Pending->value)
            ->where('upload.location.latitude', 14.5995123)
            ->where('upload.location.longitude', 120.9842234)
            ->where('upload.location.accuracy_meters', 149)
            ->where('upload.files.0.extraction.corrected_data', null));

    $extraction->forceFill(['review_status' => ReviewStatus::Verified])->save();

    $this->withSession($session)
        ->get(route('admin.uploads.show', $upload))
        ->assertInertia(fn ($page) => $page
            ->where('upload.files.0.extraction.corrected_data.document_type', 'Invoice'));
});

it('reprocesses every accepted file while preserving receiving email state', function (): void {
    Queue::fake();
    Mail::fake();
    [$admin, $upload, $file, $extraction, $link] = adminUploadActionFixture();
    $upload->forceFill([
        'email_status' => EmailStatus::Failed,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Verified,
        'review_email_status' => EmailStatus::Sent,
    ])->save();
    $file->forceFill(['ai_status' => AiStatus::Extracted, 'review_status' => ReviewStatus::Verified])->save();
    $extraction->forceFill(['review_status' => ReviewStatus::Verified, 'reviewed_at' => now(), 'reviewed_by_email' => 'reviewer@example.com'])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.reprocess', $upload))
        ->assertSessionHas('status');

    expect($upload->refresh()->ai_status)->toBe(AiStatus::Pending)
        ->and($upload->review_status)->toBe(ReviewStatus::Pending)
        ->and($upload->review_email_status)->toBe(EmailStatus::Pending)
        ->and($upload->email_status)->toBe(EmailStatus::Failed)
        ->and($file->refresh()->ai_status)->toBe(AiStatus::Pending)
        ->and($file->review_status)->toBe(ReviewStatus::Pending)
        ->and($extraction->refresh()->raw_extracted_json)->toBeNull()
        ->and($extraction->corrected_json)->toBeNull()
        ->and($extraction->reviewed_by_email)->toBeNull()
        ->and($link->refresh()->used_at)->not->toBeNull();
    Queue::assertPushed(StartAiExtraction::class, fn (StartAiExtraction $job): bool => $job->uploadId === $upload->getKey());
    Mail::assertNotSent(ReceivingUploadReceived::class);
    Mail::assertNotSent(ReceivingReviewReady::class);
});

it('reprocesses purchase orders without reopening email or review states', function (): void {
    Queue::fake();
    [$admin, $upload, $file, $extraction, $link] = adminUploadActionFixture();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $upload->forceFill([
        'upload_type_id' => $purchaseOrderType->getKey(),
        'email_status' => EmailStatus::NotRequired,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::NotRequired,
        'review_email_status' => EmailStatus::NotRequired,
    ])->save();
    $file->forceFill([
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::NotRequired,
    ])->save();
    $extraction->forceFill(['review_status' => ReviewStatus::NotRequired])->save();
    $link->delete();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.reprocess', $upload))
        ->assertSessionHas('status');

    expect($upload->refresh()->ai_status)->toBe(AiStatus::Pending)
        ->and($upload->review_status)->toBe(ReviewStatus::NotRequired)
        ->and($upload->review_email_status)->toBe(EmailStatus::NotRequired)
        ->and($upload->email_status)->toBe(EmailStatus::NotRequired)
        ->and($file->refresh()->review_status)->toBe(ReviewStatus::NotRequired)
        ->and($extraction->refresh()->review_status)->toBe(ReviewStatus::NotRequired);
});

it('resends only the review email for a completed extraction', function (): void {
    Mail::fake();
    [$admin, $upload] = adminUploadActionFixture();
    $upload->forceFill([
        'email_status' => EmailStatus::Sent,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Pending,
    ])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.resend-review', $upload))
        ->assertSessionHas('status');

    Mail::assertSent(ReceivingReviewReady::class);
    Mail::assertNotSent(ReceivingUploadReceived::class);
    expect($upload->refresh()->email_status)->toBe(EmailStatus::Sent)
        ->and($upload->review_email_status)->toBe(EmailStatus::Sent);
});

it('re-matches all receive logs against purchase orders', function (): void {
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => 'PO Number', 'value' => '716'],
        ],
        'items' => [],
    ];
    $extraction->forceFill([
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'po_number' => '716',
        'po_number_normalized' => '716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    PoExtraction::query()->create([
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_number' => '716',
        'po_number_normalized' => '716',
        'vendor_name' => 'Symmetryplast Enterprises',
        'status_normalized' => 'confirmed',
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-all-po'))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($extraction->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extraction->activePurchaseOrderLink)->not->toBeNull()
        ->and($extraction->activePurchaseOrderLink->poExtraction->po_number)->toBe('716');
});

it('re-matches receive logs scoped to a specific upload source', function (): void {
    [$admin, $uploadA, $fileA, $extractionA] = adminUploadActionFixture();
    $dataA = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => 'PO Number', 'value' => '716'],
        ],
        'items' => [],
    ];
    $extractionA->forceFill([
        'raw_extracted_json' => $dataA,
        'corrected_json' => $dataA,
        'po_number' => '716',
        'po_number_normalized' => '716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    $typeB = UploadType::query()->where('slug', 'keysys')->firstOrFail();
    $uploadB = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $typeB->getKey(),
        'uploader_user_id' => $uploadA->uploader_user_id,
        'uploader_email' => $uploadA->uploader_email,
        'latitude' => 14.5995123,
        'longitude' => 120.9842234,
        'location_accuracy_meters' => 149,
        'location_captured_at' => now(),
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'serial_number' => 888,
        'email_status' => EmailStatus::Sent,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Pending,
        'review_email_status' => EmailStatus::Pending,
    ]);
    $fileB = UploadedFile::query()->create([
        'receiving_upload_id' => $uploadB->getKey(),
        'original_file_name' => 'document_b.pdf',
        'sanitized_file_name' => 'document_b.pdf',
        'stored_file_name' => 'document_b.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/document_b.pdf',
        'r2_staging_object_key' => 'staging/document_b.pdf',
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $extractionB = AiExtraction::query()->create([
        'receiving_upload_id' => $uploadB->getKey(),
        'uploaded_file_id' => $fileB->getKey(),
        'document_type' => 'Invoice',
        'po_number' => '800',
        'po_number_normalized' => '800',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
        'raw_extracted_json' => [
            'document_type' => 'Invoice',
            'fields' => [
                ['label' => 'PO Number', 'value' => '800'],
            ],
            'items' => [],
        ],
    ]);

    PoExtraction::query()->create([
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_number' => '716',
        'po_number_normalized' => '716',
        'vendor_name' => 'Symmetryplast Enterprises',
        'status_normalized' => 'confirmed',
    ]);

    PoExtraction::query()->create([
        'source_type' => 'google_sheet',
        'sheet_slug' => 'keysys',
        'po_number' => '800',
        'po_number_normalized' => '800',
        'vendor_name' => 'Keysys Supplier',
        'status_normalized' => 'confirmed',
    ]);

    $response = $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-all-po'), [
            'upload_type_id' => $typeB->getKey(),
        ]);

    $response->assertRedirect()
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'KEYSYS INC.'));

    expect($extractionB->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extractionB->activePurchaseOrderLink)->not->toBeNull()
        ->and($extractionB->activePurchaseOrderLink->poExtraction->po_number)->toBe('800');

    expect($extractionA->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::AwaitingPurchaseOrder)
        ->and($extractionA->activePurchaseOrderLink)->toBeNull();
});

it('re-matches a single receive log against purchase orders', function (): void {
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => 'PO Number', 'value' => '716'],
        ],
        'items' => [],
    ];
    $extraction->forceFill([
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'po_number' => '716',
        'po_number_normalized' => '716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    PoExtraction::query()->create([
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_number' => '716',
        'po_number_normalized' => '716',
        'vendor_name' => 'Symmetryplast Enterprises',
        'status_normalized' => 'confirmed',
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-po', $upload))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($extraction->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extraction->activePurchaseOrderLink)->not->toBeNull()
        ->and($extraction->activePurchaseOrderLink->poExtraction->po_number)->toBe('716');
});

it('re-matches invoice with PO prefix like PO# 716 against numeric sheet PO 716', function (): void {
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => 'PO Number', 'value' => 'PO# 716'],
        ],
        'items' => [],
    ];
    $extraction->forceFill([
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'po_number' => 'PO# 716',
        'po_number_normalized' => 'po716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    PoExtraction::query()->create([
        'source_type' => 'google_sheet',
        'sheet_slug' => 'a2z2go',
        'po_number' => '716',
        'po_number_normalized' => '716',
        'vendor_name' => 'Symmetryplast Enterprises',
        'status_normalized' => 'confirmed',
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-po', $upload))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($extraction->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extraction->activePurchaseOrderLink)->not->toBeNull()
        ->and($extraction->activePurchaseOrderLink->poExtraction->po_number)->toBe('716');
});

it('queues background sync when document is awaiting PO and google sheet is configured', function (): void {
    Queue::fake();
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    config(['services.google.purchase_orders_sheet_id' => 'test-sheet-id']);

    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Unknown Supplier'],
            ['label' => 'PO Number', 'value' => '99999'],
        ],
        'items' => [],
    ];
    $extraction->forceFill([
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'po_number' => '99999',
        'po_number_normalized' => '99999',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-po', $upload))
        ->assertRedirect()
        ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, 'Google Sheet background sync has been queued'));

    Queue::assertPushed(SyncPurchaseOrderSheet::class);
});

it('synchronously syncs lane PO tab from google sheets and links invoice immediately on rematch', function (): void {
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    config(['services.google.purchase_orders_sheet_id' => 'mock-po-sheet-id']);

    Http::fake([
        'https://sheets.googleapis.com/v4/spreadsheets/mock-po-sheet-id/values/*' => Http::response([
            'values' => [
                ['PO Number', 'Supplier', 'Type', 'Status', 'Date', 'Net Total', 'VAT Total', 'Total Amount', 'Item Code', 'Item Description', 'Qty', 'Unit', 'Unit Price', 'Line Total', 'Notes'],
                ['716', 'Symmetryplast Enterprises', 'Standard', 'Confirmed', '2026-03-20', '500.00', '60.00', '560.00', 'ITEM-1', 'Test Item', '10', 'PCS', '50.00', '500.00', ''],
            ],
        ], 200),
    ]);

    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => 'PO Number', 'value' => '716'],
        ],
        'items' => [],
    ];
    $extraction->forceFill([
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'po_number' => '716',
        'po_number_normalized' => '716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-po', $upload))
        ->assertRedirect()
        ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, '1 document(s) linked to PO 716'));

    expect($extraction->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extraction->activePurchaseOrderLink)->not->toBeNull()
        ->and($extraction->activePurchaseOrderLink->poExtraction->po_number)->toBe('716');
});

it('synchronously syncs google sheets and links all invoices and DRs under every serial number when matching in receive logs page', function (): void {
    [$admin, $upload1, $file1, $extractionInvoice1] = adminUploadActionFixture();
    $type = $upload1->uploadType;
    config(['services.google.purchase_orders_sheet_id' => 'mock-po-sheet-id']);

    Http::fake([
        'https://sheets.googleapis.com/v4/spreadsheets/mock-po-sheet-id/values/*' => Http::response([
            'values' => [
                ['PO Number', 'Supplier', 'Type', 'Status', 'Date', 'Net Total', 'VAT Total', 'Total Amount', 'Item Code', 'Item Description', 'Qty', 'Unit', 'Unit Price', 'Line Total', 'Notes'],
                ['716', 'Symmetryplast Enterprises', 'Standard', 'Confirmed', '2026-03-20', '500.00', '60.00', '560.00', 'ITEM-1', 'Test Item 1', '10', 'PCS', '50.00', '500.00', ''],
                ['800', 'Symmetryplast Enterprises', 'Standard', 'Confirmed', '2026-03-21', '600.00', '72.00', '672.00', 'ITEM-2', 'Test Item 2', '20', 'PCS', '30.00', '600.00', ''],
            ],
        ], 200),
    ]);

    // Upload 1 (SN 1) has an Invoice for PO 716 and a Delivery Receipt (DR) for PO 716
    $dataInvoice1 = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => 'PO Number', 'value' => '716'],
        ],
        'items' => [],
    ];
    $extractionInvoice1->forceFill([
        'raw_extracted_json' => $dataInvoice1,
        'corrected_json' => $dataInvoice1,
        'po_number' => '716',
        'po_number_normalized' => '716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    $fileDr1 = UploadedFile::query()->create([
        'receiving_upload_id' => $upload1->getKey(),
        'original_file_name' => 'dr.pdf',
        'sanitized_file_name' => 'dr.pdf',
        'stored_file_name' => 'dr.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/dr.pdf',
        'r2_staging_object_key' => 'staging/dr.pdf',
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);

    $extractionDr1 = AiExtraction::query()->create([
        'receiving_upload_id' => $upload1->getKey(),
        'uploaded_file_id' => $fileDr1->getKey(),
        'document_type' => 'Delivery Receipt',
        'po_number' => '716',
        'po_number_normalized' => '716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
        'raw_extracted_json' => [
            'document_type' => 'Delivery Receipt',
            'fields' => [
                ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
                ['label' => 'PO Number', 'value' => '716'],
            ],
            'items' => [],
        ],
        'ai_status' => AiStatus::Extracted,
        'extracted_at' => now(),
    ]);

    // Upload 2 (SN 2) has an Invoice for PO 800
    $upload2 = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $upload1->uploader_user_id,
        'uploader_email' => $upload1->uploader_email,
        'latitude' => 14.5995123,
        'longitude' => 120.9842234,
        'location_accuracy_meters' => 149,
        'location_captured_at' => now(),
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'serial_number' => 2,
        'email_status' => EmailStatus::Sent,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Pending,
        'review_email_status' => EmailStatus::Pending,
    ]);
    $file2 = UploadedFile::query()->create([
        'receiving_upload_id' => $upload2->getKey(),
        'original_file_name' => 'doc2.pdf',
        'sanitized_file_name' => 'doc2.pdf',
        'stored_file_name' => 'doc2.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/doc2.pdf',
        'r2_staging_object_key' => 'staging/doc2.pdf',
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $extractionInvoice2 = AiExtraction::query()->create([
        'receiving_upload_id' => $upload2->getKey(),
        'uploaded_file_id' => $file2->getKey(),
        'document_type' => 'Invoice',
        'po_number' => '800',
        'po_number_normalized' => '800',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
        'raw_extracted_json' => [
            'document_type' => 'Invoice',
            'fields' => [
                ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
                ['label' => 'PO Number', 'value' => '800'],
            ],
            'items' => [],
        ],
        'ai_status' => AiStatus::Extracted,
        'extracted_at' => now(),
    ]);

    // Execute match for this specific upload type from receive logs page
    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-all-po'), [
            'upload_type_id' => $type->getKey(),
        ])
        ->assertRedirect()
        ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, '3 documents checked, 3 linked'));

    // Check that every invoice and DR in SN 1 is linked to PO 716
    expect($extractionInvoice1->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extractionInvoice1->activePurchaseOrderLink)->not->toBeNull()
        ->and($extractionInvoice1->activePurchaseOrderLink->poExtraction->po_number)->toBe('716');

    expect($extractionDr1->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extractionDr1->activePurchaseOrderLink)->not->toBeNull()
        ->and($extractionDr1->activePurchaseOrderLink->poExtraction->po_number)->toBe('716');

    // Check that invoice in SN 2 is linked to PO 800
    expect($extractionInvoice2->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extractionInvoice2->activePurchaseOrderLink)->not->toBeNull()
        ->and($extractionInvoice2->activePurchaseOrderLink->poExtraction->po_number)->toBe('800');
});

it('cross-lane syncs google sheets when matching in receive logs page and PO is in another lane tab', function (): void {
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    $keysysType = UploadType::query()->where('slug', 'keysys')->firstOrFail();
    $upload->update(['upload_type_id' => $keysysType->getKey()]);

    config(['services.google.purchase_orders_sheet_id' => 'mock-po-sheet-id']);

    Http::fake([
        'https://sheets.googleapis.com/v4/spreadsheets/mock-po-sheet-id/values/*KEYSYS*' => Http::response([
            'values' => [
                ['PO Number', 'Supplier', 'Type', 'Status', 'Date', 'Net Total', 'VAT Total', 'Total Amount', 'Item Code', 'Item Description', 'Qty', 'Unit', 'Unit Price', 'Line Total', 'Notes'],
            ],
        ], 200),
        'https://sheets.googleapis.com/v4/spreadsheets/mock-po-sheet-id/values/*' => Http::response([
            'values' => [
                ['PO Number', 'Supplier', 'Type', 'Status', 'Date', 'Net Total', 'VAT Total', 'Total Amount', 'Item Code', 'Item Description', 'Qty', 'Unit', 'Unit Price', 'Line Total', 'Notes'],
                ['716', 'Symmetryplast Enterprises', 'Standard', 'Confirmed', '2026-03-20', '500.00', '60.00', '560.00', 'ITEM-1', 'Test Item 1', '10', 'PCS', '50.00', '500.00', ''],
            ],
        ], 200),
    ]);

    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => 'PO Number', 'value' => '716'],
        ],
        'items' => [],
    ];
    $extraction->forceFill([
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'po_number' => '716',
        'po_number_normalized' => '716',
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
    ])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-all-po'), [
            'upload_type_id' => $keysysType->getKey(),
        ])
        ->assertRedirect()
        ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, '1 documents checked, 1 linked'));

    expect($extraction->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($extraction->activePurchaseOrderLink)->not->toBeNull()
        ->and($extraction->activePurchaseOrderLink->poExtraction->po_number)->toBe('716');
});

it('includes note in rematch status when google sheet id is not configured', function (): void {
    [$admin, $upload] = adminUploadActionFixture();
    config(['services.google.purchase_orders_sheet_id' => '']);
    GoogleSheetConfig::query()->update(['spreadsheet_id' => '']);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.rematch-po', $upload))
        ->assertRedirect()
        ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, 'Google Sheet ID is not configured'));
});

/** @return array{User, ReceivingUpload, UploadedFile, AiExtraction, ReviewLink} */
function adminUploadActionFixture(): array
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $uploader = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(), 'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(), 'uploader_email' => $uploader->email,
        'latitude' => 14.5995123, 'longitude' => 120.9842234,
        'location_accuracy_meters' => 149, 'location_captured_at' => now(),
        'r2_bucket' => 'test', 'r2_prefix' => 'receiving/test', 'file_count' => 1,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'original_file_name' => 'document.pdf',
        'sanitized_file_name' => 'document.pdf', 'stored_file_name' => 'document.pdf',
        'file_extension' => 'pdf', 'r2_bucket' => 'test', 'r2_object_key' => 'receiving/document.pdf',
        'r2_staging_object_key' => 'staging/document.pdf', 'original_file_size' => 100,
        'final_file_size' => 100, 'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf', 'ai_status' => AiStatus::Extracted,
    ]);
    $data = [
        'document_type' => 'Invoice',
        'fields' => [['label' => 'Company Name', 'value' => 'ABC Supplier']],
        'items' => [],
    ];
    $extraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice', 'raw_extracted_json' => $data, 'corrected_json' => $data,
        'ai_status' => AiStatus::Extracted, 'extracted_at' => now(),
    ]);
    $link = ReviewLink::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'email' => $uploader->email,
        'upload_type_id' => $type->getKey(), 'token_hash' => hash('sha256', fake()->uuid()),
        'expires_at' => now()->addHour(),
    ]);

    return [$admin, $upload, $file, $extraction, $link];
}
