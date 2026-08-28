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
