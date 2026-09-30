<?php

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkSource;
use App\Enums\PurchaseOrderLinkStatus;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('finds an upload by a value nested in AI extracted data', function (): void {
    [$admin, $target, $type] = uploadLogSearchFixture();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index', [
            'search' => 'ACME-NEEDLE-4821',
            'review_email_status' => EmailStatus::Pending->value,
            'upload_type_id' => $type->getKey(),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/uploads/index')
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $target->getKey())
            ->where('filters.search', 'ACME-NEEDLE-4821')
            ->where('filters.review_email_status', 'pending'));
});

it('treats SQL wildcard characters as literal search input', function (): void {
    [$admin, $target] = uploadLogSearchFixture();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index', ['search' => '%']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $target->getKey()));
});

it('rejects an unbounded receive log search term', function (): void {
    [$admin] = uploadLogSearchFixture();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->from(route('admin.uploads.index'))
        ->get(route('admin.uploads.index', ['search' => str_repeat('a', 101)]))
        ->assertRedirect(route('admin.uploads.index'))
        ->assertSessionHasErrors('search');
});

it('groups partial AI failures under the simple failed filter', function (): void {
    [$admin, $target] = uploadLogSearchFixture();
    $target->forceFill([
        'ai_status' => AiStatus::PartialFailed,
    ])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index', ['ai_status' => 'failed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $target->getKey()));
});

it('groups the internal manual review state under completed AI extraction', function (): void {
    [$admin, $target] = uploadLogSearchFixture();
    $target->forceFill(['ai_status' => AiStatus::ManualReview])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index', [
            'search' => 'ACME-NEEDLE-4821',
            'ai_status' => 'completed',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $target->getKey()));
});

it('rejects internal or unknown values outside the public status filters', function (): void {
    [$admin] = uploadLogSearchFixture();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->from(route('admin.uploads.index'))
        ->get(route('admin.uploads.index', [
            'review_email_status' => 'partially_sent',
            'ai_status' => AiStatus::ManualReview->value,
        ]))
        ->assertRedirect(route('admin.uploads.index'))
        ->assertSessionHasErrors(['review_email_status', 'ai_status']);
});

it('shows only searchable purchase order uploads on the dedicated admin page', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $standardType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    makeSearchableUpload($purchaseOrderType, 'po-previous.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-PREVIOUS']],
        'items' => [],
    ]);
    $purchaseOrder = makeSearchableUpload($purchaseOrderType, 'po-42.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [
            ['label' => 'PO Number', 'value' => 'PO-SEARCH-0042'],
            ['label' => 'Vendor Name', 'value' => 'Acme Supplies'],
        ],
        'items' => [],
    ]);
    makeSearchableUpload($standardType, 'invoice.pdf', [
        'document_type' => 'Invoice',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-SEARCH-0042']],
        'items' => [],
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.index', [
            'search' => 'PO-SEARCH-0042',
            'ai_status' => 'completed',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/index')
            ->where('pageMode', 'purchase_orders')
            ->where('basePath', '/admin/purchase-orders')
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $purchaseOrder->getKey())
            ->where('uploads.data.0.serial_prefix', 'POSN')
            ->where('uploads.data.0.serial_number', 2)
            ->where('uploads.data.0.ai_status', AiStatus::Extracted->value));

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.index', ['search' => 'POSN-2']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $purchaseOrder->getKey())
            ->where('uploads.data.0.serial_prefix', 'POSN')
            ->where('uploads.data.0.serial_number', 2));
});

/** @return array{User, ReceivingUpload, UploadType} */
function uploadLogSearchFixture(): array
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $type = UploadType::query()->firstOrFail();

    $target = makeSearchableUpload($type, 'needle.pdf', [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Supplier reference', 'value' => 'ACME-NEEDLE-4821'],
            ['label' => 'Literal marker', 'value' => '100% complete'],
        ],
        'items' => [],
    ]);
    makeSearchableUpload($type, 'ordinary.pdf', [
        'document_type' => 'Delivery Receipt',
        'fields' => [['label' => 'Supplier reference', 'value' => 'ORDINARY-1000']],
        'items' => [],
    ]);

    return [$admin, $target, $type];
}

/** @param array<string, mixed> $data */
function makeSearchableUpload(UploadType $type, string $fileName, array $data): ReceivingUpload
{
    $uploader = User::factory()->create();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'ai_status' => AiStatus::Extracted,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => $fileName,
        'sanitized_file_name' => $fileName,
        'stored_file_name' => $fileName,
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => "receiving/{$fileName}",
        'r2_staging_object_key' => "staging/{$upload->getKey()}/{$fileName}",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => $data['document_type'],
        'raw_extracted_json' => $data,
        'corrected_json' => null,
        'ai_status' => AiStatus::Extracted,
    ]);

    return $upload;
}

it('excludes purchase order uploads from the general receive logs page', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $standardType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

    makeSearchableUpload($purchaseOrderType, 'po-42.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [],
        'items' => [],
    ]);

    $standardUpload = makeSearchableUpload($standardType, 'invoice.pdf', [
        'document_type' => 'Invoice',
        'fields' => [],
        'items' => [],
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/uploads/index')
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $standardUpload->getKey())
            ->where('uploads.data.0.serial_prefix', 'SN')
            ->where('uploads.data.0.serial_number', $standardUpload->serial_number));
});

it('returns linked_receipts for purchase orders and po_link_details for receive logs', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $standardType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

    $poUpload = makeSearchableUpload($purchaseOrderType, 'po-100.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-TEST-100']],
        'items' => [],
    ]);
    $poExtraction = PoExtraction::query()->create([
        'ai_extraction_id' => $poUpload->extractions->first()->getKey(),
        'receiving_upload_id' => $poUpload->getKey(),
        'po_number' => 'PO-TEST-100',
        'po_number_normalized' => 'POTEST100',
        'po_date' => '2026-08-01',
        'po_date_value' => '2026-08-01',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);

    $invoiceUpload = makeSearchableUpload($standardType, 'inv-100.pdf', [
        'document_type' => 'Invoice',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-TEST-100']],
        'items' => [],
    ]);
    $invoiceExtraction = $invoiceUpload->extractions->first();
    $invoiceExtraction->forceFill([
        'po_number' => 'PO-TEST-100',
        'po_number_normalized' => 'POTEST100',
        'po_link_status' => PurchaseOrderLinkStatus::Linked,
    ])->save();

    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $poExtraction->getKey(),
        'ai_extraction_id' => $invoiceExtraction->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    // Test purchase orders index
    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/index')
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $poUpload->getKey())
            ->where('uploads.data.0.purchase_order_status', 'arrived')
            ->has('uploads.data.0.linked_receipts', 1)
            ->where('uploads.data.0.linked_receipts.0.id', $invoiceUpload->getKey())
            ->where('uploads.data.0.linked_receipts.0.serial_prefix', 'SN')
            ->where('uploads.data.0.linked_receipts.0.serial_number', $invoiceUpload->serial_number));

    // Test receive logs index
    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/uploads/index')
            ->has('uploads.data', 1)
            ->where('uploads.data.0.id', $invoiceUpload->getKey())
            ->where('uploads.data.0.purchase_order_status', 'linked')
            ->where('uploads.data.0.po_link_details.status', 'linked')
            ->where('uploads.data.0.po_link_details.total_invoices', 1)
            ->where('uploads.data.0.po_link_details.linked_invoices', 1)
            ->where('uploads.data.0.po_link_details.po_numbers', ['PO-TEST-100']));
});

it('calculates po_link_details totals using invoice document types only when invoices and delivery receipts are present', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $standardType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();

    // Upload with 4 files: 2 Invoices (linked to 715 and 716) and 2 Delivery Receipts (unlinked)
    $upload = makeSearchableUpload($standardType, 'inv-715.pdf', [
        'document_type' => 'Sales Invoice',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-715']],
        'items' => [],
    ]);
    $inv1 = $upload->extractions->first();
    $inv1->forceFill([
        'po_number' => 'PO-715',
        'po_number_normalized' => 'PO715',
        'po_link_status' => PurchaseOrderLinkStatus::Linked,
    ])->save();

    // PO for 715
    $poUpload715 = makeSearchableUpload($purchaseOrderType, 'po-715.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-715']],
        'items' => [],
    ]);
    $poExt715 = PoExtraction::query()->create([
        'ai_extraction_id' => $poUpload715->extractions->first()->getKey(),
        'receiving_upload_id' => $poUpload715->getKey(),
        'po_number' => 'PO-715',
        'po_number_normalized' => 'PO715',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);
    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $poExt715->getKey(),
        'ai_extraction_id' => $inv1->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    // Second Invoice (716)
    $file2 = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'inv-716.pdf',
        'sanitized_file_name' => 'inv-716.pdf',
        'stored_file_name' => 'inv-716.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/inv-716.pdf',
        'r2_staging_object_key' => "staging/{$upload->getKey()}/inv-716.pdf",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $inv2 = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file2->getKey(),
        'document_type' => 'Invoice',
        'raw_extracted_json' => ['document_type' => 'Invoice'],
        'po_number' => 'PO-716',
        'po_number_normalized' => 'PO716',
        'po_link_status' => PurchaseOrderLinkStatus::Linked,
        'ai_status' => AiStatus::Extracted,
    ]);

    // PO for 716
    $poUpload716 = makeSearchableUpload($purchaseOrderType, 'po-716.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-716']],
        'items' => [],
    ]);
    $poExt716 = PoExtraction::query()->create([
        'ai_extraction_id' => $poUpload716->extractions->first()->getKey(),
        'receiving_upload_id' => $poUpload716->getKey(),
        'po_number' => 'PO-716',
        'po_number_normalized' => 'PO716',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);
    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $poExt716->getKey(),
        'ai_extraction_id' => $inv2->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    // 2 Delivery Receipts (unlinked)
    foreach (['dr-1.pdf', 'dr-2.pdf'] as $drName) {
        $drFile = UploadedFile::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'original_file_name' => $drName,
            'sanitized_file_name' => $drName,
            'stored_file_name' => $drName,
            'file_extension' => 'pdf',
            'r2_bucket' => 'test',
            'r2_object_key' => "receiving/{$drName}",
            'r2_staging_object_key' => "staging/{$upload->getKey()}/{$drName}",
            'original_file_size' => 100,
            'final_file_size' => 100,
            'declared_content_type' => 'application/pdf',
            'content_type' => 'application/pdf',
            'ai_status' => AiStatus::Extracted,
        ]);
        AiExtraction::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'uploaded_file_id' => $drFile->getKey(),
            'document_type' => 'Delivery Receipt',
            'raw_extracted_json' => ['document_type' => 'Delivery Receipt'],
            'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
            'ai_status' => AiStatus::Extracted,
        ]);
    }

    $upload->update(['file_count' => 4]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/uploads/index')
            ->where('uploads.data.0.id', $upload->getKey())
            ->where('uploads.data.0.purchase_order_status', 'linked')
            ->where('uploads.data.0.po_link_details.status', 'linked')
            // Crucial: total is 2 (only the 2 invoices), NOT 4 (which included the 2 DRs)
            ->where('uploads.data.0.po_link_details.total_invoices', 2)
            ->where('uploads.data.0.po_link_details.linked_invoices', 2)
            ->where('uploads.data.0.po_link_details.po_numbers', ['PO-715', 'PO-716']));
});

it('calculates po_link_details totals using delivery receipts when no invoices are present', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $standardType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();

    // Upload with 2 Delivery Receipts: 1 Linked, 1 Awaiting
    $upload = makeSearchableUpload($standardType, 'dr-100.pdf', [
        'document_type' => 'Delivery Receipt',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-731']],
        'items' => [],
    ]);
    $dr1 = $upload->extractions->first();
    $dr1->forceFill([
        'po_number' => 'PO-731',
        'po_number_normalized' => 'PO731',
        'po_link_status' => PurchaseOrderLinkStatus::Linked,
    ])->save();

    // PO for 731
    $poUpload731 = makeSearchableUpload($purchaseOrderType, 'po-731.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-731']],
        'items' => [],
    ]);
    $poExt731 = PoExtraction::query()->create([
        'ai_extraction_id' => $poUpload731->extractions->first()->getKey(),
        'receiving_upload_id' => $poUpload731->getKey(),
        'po_number' => 'PO-731',
        'po_number_normalized' => 'PO731',
        'arrival_status' => PurchaseOrderArrivalStatus::Arrived,
    ]);
    PurchaseOrderDocumentLink::query()->create([
        'po_extraction_id' => $poExt731->getKey(),
        'ai_extraction_id' => $dr1->getKey(),
        'source' => PurchaseOrderLinkSource::Automatic,
    ]);

    // Second DR (awaiting PO)
    $file2 = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'dr-101.pdf',
        'sanitized_file_name' => 'dr-101.pdf',
        'stored_file_name' => 'dr-101.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/dr-101.pdf',
        'r2_staging_object_key' => "staging/{$upload->getKey()}/dr-101.pdf",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file2->getKey(),
        'document_type' => 'Delivery Receipt',
        'raw_extracted_json' => ['document_type' => 'Delivery Receipt'],
        'po_link_status' => PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
        'ai_status' => AiStatus::Extracted,
    ]);

    $upload->update(['file_count' => 2]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/uploads/index')
            ->where('uploads.data.0.id', $upload->getKey())
            ->where('uploads.data.0.purchase_order_status', 'linked')
            ->where('uploads.data.0.po_link_details.status', 'linked')
            // Falls back to delivery receipt count: 1 linked out of 2 total DRs
            ->where('uploads.data.0.po_link_details.total_invoices', 2)
            ->where('uploads.data.0.po_link_details.linked_invoices', 1)
            ->where('uploads.data.0.po_link_details.po_numbers', ['PO-731']));
});
