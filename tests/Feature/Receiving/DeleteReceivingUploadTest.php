<?php

use App\Enums\AiStatus;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkSource;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\WarehouseAllocationMethod;
use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseDeliveryStatus;
use App\Enums\WarehouseStockSource;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Models\ActivityLog;
use App\Models\AiExtraction;
use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetLog;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Models\WarehouseAllocation;
use App\Models\WarehouseDelivery;
use App\Models\WarehouseDeliveryLine;
use App\Models\WarehouseItem;
use App\Models\WarehouseStockLot;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('allows an authorized admin to delete a standard receive log', function (): void {
    $disk = Storage::fake((string) config('receiving.disk'));
    [$admin, $upload, $file, $extraction, $link] = deleteUploadFixture();

    $disk->put((string) $file->r2_object_key, 'file content');
    $disk->put((string) $file->r2_staging_object_key, 'staging content');
    expect($disk->exists((string) $file->r2_object_key))->toBeTrue();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.uploads.destroy', $upload))
        ->assertRedirect(route('admin.uploads.index'))
        ->assertSessionHas('status');

    expect(ReceivingUpload::query()->find($upload->getKey()))->toBeNull()
        ->and(UploadedFile::query()->find($file->getKey()))->toBeNull()
        ->and(AiExtraction::query()->find($extraction->getKey()))->toBeNull()
        ->and(ReviewLink::query()->find($link->getKey()))->toBeNull()
        ->and($disk->exists((string) $file->r2_object_key))->toBeFalse()
        ->and($disk->exists((string) $file->r2_staging_object_key))->toBeFalse();

    expect(ActivityLog::query()
        ->where('action', 'upload_deleted')
        ->where('status', 'success')
        ->exists())->toBeTrue();
});

it('allows an authorized admin to delete a purchase order and reconciles linked invoices', function (): void {
    $disk = Storage::fake((string) config('receiving.disk'));
    [$admin, $poUpload, $poFile, $poExtraction] = deletePurchaseOrderFixture('PO-999');
    [$uploader, $invoiceUpload, $invoiceFile, $invoiceExtraction] = deleteInvoiceFixture('PO-999');

    /** @var PurchaseOrderLinker $linker */
    $linker = app(PurchaseOrderLinker::class);
    $linker->link($invoiceExtraction, $poExtraction, $admin, PurchaseOrderLinkSource::Manual);

    expect($invoiceExtraction->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($poExtraction->refresh()->arrival_status)->toBe(PurchaseOrderArrivalStatus::Arrived);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.uploads.destroy', $poUpload))
        ->assertRedirect(route('admin.purchase-orders.index'))
        ->assertSessionHas('status');

    expect(ReceivingUpload::query()->find($poUpload->getKey()))->toBeNull()
        ->and(PoExtraction::query()->find($poExtraction->getKey()))->toBeNull()
        ->and(PurchaseOrderDocumentLink::query()->where('po_extraction_id', $poExtraction->getKey())->exists())->toBeFalse();

    // The invoice is now no longer linked, its status is updated
    expect($invoiceExtraction->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::AwaitingPurchaseOrder);

    expect(ActivityLog::query()
        ->where('action', 'purchase_order_deleted')
        ->where('status', 'success')
        ->exists())->toBeTrue();
});

it('reconciles PO arrival status when a linked receive log is deleted', function (): void {
    Storage::fake((string) config('receiving.disk'));
    [$admin, $poUpload, $poFile, $poExtraction] = deletePurchaseOrderFixture('PO-888');
    [$uploader, $invoiceUpload, $invoiceFile, $invoiceExtraction] = deleteInvoiceFixture('PO-888');

    /** @var PurchaseOrderLinker $linker */
    $linker = app(PurchaseOrderLinker::class);
    $linker->link($invoiceExtraction, $poExtraction, $admin, PurchaseOrderLinkSource::Manual);

    expect($poExtraction->refresh()->arrival_status)->toBe(PurchaseOrderArrivalStatus::Arrived);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.uploads.destroy', $invoiceUpload))
        ->assertRedirect(route('admin.uploads.index'))
        ->assertSessionHas('status');

    expect(ReceivingUpload::query()->find($invoiceUpload->getKey()))->toBeNull();

    // PO status reverts to Pending since no active links remain
    expect($poExtraction->refresh()->arrival_status)->toBe(PurchaseOrderArrivalStatus::Pending);
});

it('prevents deletion if warehouse stock lots from the upload have active delivery allocations', function (): void {
    Storage::fake((string) config('receiving.disk'));
    [$admin, $upload, $file, $extraction] = deleteUploadFixture();

    $item = WarehouseItem::query()->create([
        'identity_key' => 'item:test-allocated',
        'sku_number' => 'SKU-ALLOC',
        'description' => 'Allocated Item',
        'description_normalized' => 'allocated item',
        'base_unit' => 'pcs',
    ]);

    $lot = WarehouseStockLot::query()->create([
        'warehouse_item_id' => $item->getKey(),
        'source_type' => WarehouseStockSource::Arrival,
        'source_key' => "arrival:test:{$upload->getKey()}",
        'receiving_upload_id' => $upload->getKey(),
        'quantity_received' => 50,
        'received_at' => now(),
        'received_date_quality' => WarehouseDateQuality::Confirmed,
        'confirmed_by_user_id' => $admin->getKey(),
        'confirmed_at' => now(),
    ]);

    $delivery = WarehouseDelivery::query()->create([
        'customer_name' => 'Customer ABC',
        'status' => WarehouseDeliveryStatus::Draft,
    ]);

    $line = WarehouseDeliveryLine::query()->create([
        'warehouse_delivery_id' => $delivery->getKey(),
        'warehouse_item_id' => $item->getKey(),
        'quantity' => 10,
        'unit' => 'pcs',
    ]);

    WarehouseAllocation::query()->create([
        'warehouse_delivery_line_id' => $line->getKey(),
        'warehouse_stock_lot_id' => $lot->getKey(),
        'quantity_allocated' => 10,
        'allocation_method' => WarehouseAllocationMethod::Fifo,
        'allocated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->from(route('admin.uploads.index'))
        ->delete(route('admin.uploads.destroy', $upload))
        ->assertSessionHasErrors(['upload']);

    // Record and stock lot must remain untouched
    expect(ReceivingUpload::query()->find($upload->getKey()))->not->toBeNull()
        ->and(WarehouseStockLot::query()->find($lot->getKey()))->not->toBeNull();
});

it('removes unallocated warehouse stock lots when an upload is deleted', function (): void {
    Storage::fake((string) config('receiving.disk'));
    [$admin, $upload, $file, $extraction] = deleteUploadFixture();

    $item = WarehouseItem::query()->create([
        'identity_key' => 'item:test-unallocated',
        'sku_number' => 'SKU-UNALLOC',
        'description' => 'Unallocated Item',
        'description_normalized' => 'unallocated item',
        'base_unit' => 'pcs',
    ]);

    $lot = WarehouseStockLot::query()->create([
        'warehouse_item_id' => $item->getKey(),
        'source_type' => WarehouseStockSource::Arrival,
        'source_key' => "arrival:test:unalloc:{$upload->getKey()}",
        'receiving_upload_id' => $upload->getKey(),
        'quantity_received' => 20,
        'received_at' => now(),
        'received_date_quality' => WarehouseDateQuality::Confirmed,
        'confirmed_by_user_id' => $admin->getKey(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.uploads.destroy', $upload))
        ->assertSessionHas('status');

    expect(ReceivingUpload::query()->find($upload->getKey()))->toBeNull()
        ->and(WarehouseStockLot::query()->find($lot->getKey()))->toBeNull();
});

it('resets Google Sheet sync log when a synced upload is deleted', function (): void {
    Storage::fake((string) config('receiving.disk'));
    [$admin, $upload] = deleteUploadFixture();

    GoogleSheetConfig::query()->create([
        'slug' => 'test-sheet',
        'name' => 'Test Sheet',
    ]);

    $sheetLog = GoogleSheetLog::query()->create([
        'sheet_slug' => 'test-sheet',
        'serial_number' => 100,
        'is_synced_to_db' => true,
        'synced_receiving_upload_id' => $upload->getKey(),
        'synced_at' => now(),
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.uploads.destroy', $upload))
        ->assertSessionHas('status');

    expect($sheetLog->refresh()->is_synced_to_db)->toBeFalse()
        ->and($sheetLog->synced_receiving_upload_id)->toBeNull()
        ->and($sheetLog->synced_at)->toBeNull();
});

it('denies deletion to users without permissions or non-admins', function (): void {
    [$admin, $upload] = deleteUploadFixture();
    $uploader = $upload->uploader;

    $this->actingAs($uploader)
        ->delete(route('admin.uploads.destroy', $upload))
        ->assertForbidden();
});

/** @return array{User, ReceivingUpload, UploadedFile, AiExtraction, ReviewLink} */
function deleteUploadFixture(): array
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $uploader = User::factory()->create();
    $type = UploadType::query()->where('workflow', '!=', 'purchase_order')->firstOrFail();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'invoice.pdf',
        'sanitized_file_name' => 'invoice.pdf',
        'stored_file_name' => 'invoice.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/invoice.pdf',
        'r2_staging_object_key' => 'staging/invoice.pdf',
        'original_file_size' => 200,
        'declared_content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $data = [
        'document_type' => 'Invoice',
        'fields' => [['label' => 'PO Number', 'value' => 'PO-12345']],
        'items' => [],
    ];
    $extraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice',
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'ai_status' => AiStatus::Extracted,
        'extracted_at' => now(),
    ]);
    $link = ReviewLink::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'email' => $uploader->email,
        'upload_type_id' => $type->getKey(),
        'token_hash' => hash('sha256', fake()->uuid()),
        'expires_at' => now()->addHour(),
    ]);

    return [$admin, $upload, $file, $extraction, $link];
}

/** @return array{User, ReceivingUpload, UploadedFile, PoExtraction} */
function deletePurchaseOrderFixture(string $poNumber): array
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $normalizer = app(PurchaseOrderDataNormalizer::class);
    $normalized = $normalizer->normalizeIdentifier($poNumber);
    $type = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $admin->getKey(),
        'uploader_email' => $admin->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'po/test',
        'file_count' => 1,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'po.pdf',
        'sanitized_file_name' => 'po.pdf',
        'stored_file_name' => 'po.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'po/po.pdf',
        'r2_staging_object_key' => 'staging/po.pdf',
        'original_file_size' => 300,
        'declared_content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $aiExtraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Purchase Order',
        'ai_status' => AiStatus::Extracted,
    ]);
    $poExtraction = PoExtraction::query()->create([
        'ai_extraction_id' => $aiExtraction->getKey(),
        'receiving_upload_id' => $upload->getKey(),
        'po_number' => $poNumber,
        'po_number_normalized' => $normalized,
        'po_date' => '2026-09-01',
        'po_date_value' => '2026-09-01',
        'arrival_status' => PurchaseOrderArrivalStatus::Pending,
    ]);
    PoExtractionItem::query()->create([
        'po_extraction_id' => $poExtraction->getKey(),
        'item_code' => 'ITEM-1',
        'product_description' => 'Test Item 1',
        'quantity' => '100',
    ]);

    return [$admin, $upload, $file, $poExtraction];
}

/** @return array{User, ReceivingUpload, UploadedFile, AiExtraction} */
function deleteInvoiceFixture(string $poNumber): array
{
    $uploader = User::factory()->create();
    $normalizer = app(PurchaseOrderDataNormalizer::class);
    $normalized = $normalizer->normalizeIdentifier($poNumber);
    $type = UploadType::query()->where('workflow', '!=', 'purchase_order')->firstOrFail();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'invoices/test',
        'file_count' => 1,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'inv.pdf',
        'sanitized_file_name' => 'inv.pdf',
        'stored_file_name' => 'inv.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'invoices/inv.pdf',
        'r2_staging_object_key' => 'staging/inv.pdf',
        'original_file_size' => 250,
        'declared_content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'PO Number', 'value' => $poNumber],
            ['label' => 'PO Date', 'value' => '2026-09-01'],
        ],
        'items' => [
            ['item_code' => 'ITEM-1', 'description' => 'Test Item 1', 'quantity' => '50'],
        ],
    ];
    $extraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice',
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'ai_status' => AiStatus::Extracted,
        'po_number' => $poNumber,
        'po_number_normalized' => $normalized,
        'po_link_status' => PurchaseOrderLinkStatus::ReadyToLink,
    ]);

    return [$uploader, $upload, $file, $extraction];
}
