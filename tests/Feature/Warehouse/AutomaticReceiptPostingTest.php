<?php

namespace Tests\Feature\Warehouse;

use App\Enums\AiStatus;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\WarehouseDateQuality;
use App\Enums\WarehouseStockSource;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Features\Receiving\Services\ReceivingUploadReprocessor;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderItemArrival;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Models\WarehouseStockLot;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AutomaticReceiptPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]);
    }

    public function test_live_receiving_upload_automatically_posts_stock_upon_po_link(): void
    {
        $normalizer = app(PurchaseOrderDataNormalizer::class);

        // 1. Create a confirmed sheet PO
        $po = PoExtraction::query()->create([
            'source_type' => 'google_sheet',
            'sheet_slug' => 'po_master',
            'po_number' => 'PO-AUTO-100',
            'po_number_normalized' => $normalizer->normalizeIdentifier('PO-AUTO-100'),
            'vendor_name' => 'Acme Supplier',
            'source_status' => 'Confirmed',
            'status_normalized' => 'confirmed',
            'arrival_status' => PurchaseOrderArrivalStatus::Pending,
            'po_date' => '2026-08-01',
            'po_date_value' => '2026-08-01',
        ]);

        $poItem1 = $po->items()->create([
            'sort_order' => 1,
            'source_line_id' => 'po:poauto100:line:1',
            'item_code' => 'SKU-AUTO-1',
            'product_description' => 'Automated Widget',
            'quantity' => '20',
            'unit' => 'pcs',
            'unit_price' => '15.00',
            'line_total' => '300.00',
            'is_financial_adjustment' => false,
        ]);

        $poItem2 = $po->items()->create([
            'sort_order' => 2,
            'source_line_id' => 'po:poauto100:line:2',
            'item_code' => '',
            'product_description' => 'DISCOUNT 5%',
            'quantity' => null,
            'unit' => null,
            'unit_price' => null,
            'line_total' => '-15.00',
            'is_financial_adjustment' => true,
        ]);

        // 2. Create live receiving upload with completion date
        $user = User::factory()->create();
        $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

        $uploadCompletionDate = CarbonImmutable::parse('2026-08-15 14:30:00');
        $upload = ReceivingUpload::query()->create([
            'submission_id' => fake()->uuid(),
            'upload_type_id' => $type->getKey(),
            'uploader_user_id' => $user->getKey(),
            'uploader_email' => $user->email,
            'r2_bucket' => 'test',
            'r2_prefix' => 'receiving/test',
            'file_count' => 1,
            'is_historical' => false,
            'upload_completed_at' => $uploadCompletionDate,
        ]);

        $file = UploadedFile::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'original_file_name' => 'receipt.pdf',
            'sanitized_file_name' => 'receipt.pdf',
            'stored_file_name' => 'receipt.pdf',
            'file_extension' => 'pdf',
            'r2_bucket' => 'test',
            'r2_object_key' => 'receiving/receipt.pdf',
            'r2_staging_object_key' => 'staging/receipt.pdf',
            'original_file_size' => 100,
            'final_file_size' => 100,
            'declared_content_type' => 'application/pdf',
            'content_type' => 'application/pdf',
        ]);

        $extraction = AiExtraction::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'uploaded_file_id' => $file->getKey(),
            'document_type' => 'Invoice',
            'raw_extracted_json' => [
                'document_type' => 'Invoice',
                'fields' => [
                    ['label' => 'Company Name', 'value' => 'Acme Supplier'],
                    ['label' => 'PO Number', 'value' => 'PO-AUTO-100'],
                    ['label' => 'PO Date', 'value' => '2026-08-01'],
                ],
                'items' => [
                    [
                        'itemCode' => 'SKU-AUTO-1',
                        'description' => 'Automated Widget',
                        'quantity' => '20',
                        'unit' => 'pcs',
                        'unitPrice' => '15.00',
                    ],
                    [
                        'description' => 'DISCOUNT 5%',
                        'amount' => '-15.00',
                    ],
                ],
            ],
            'corrected_json' => null,
        ]);

        // 3. Link extraction via PurchaseOrderLinker
        $status = app(PurchaseOrderLinker::class)->syncExtraction($extraction);
        $this->assertSame(PurchaseOrderLinkStatus::Linked, $status);

        // 4. Verify arrival records
        $arrivals = PurchaseOrderItemArrival::query()
            ->where('receiving_upload_id', $upload->getKey())
            ->get();

        $this->assertCount(2, $arrivals);

        $productArrival = $arrivals->firstWhere('item_code', 'SKU-AUTO-1');
        $this->assertNotNull($productArrival);
        $this->assertSame('posted', $productArrival->posting_status);
        $this->assertNotNull($productArrival->warehouse_stock_lot_id);
        $this->assertNotNull($productArrival->posted_at);

        $discountArrival = $arrivals->firstWhere('item_code', null);
        $this->assertNotNull($discountArrival);
        $this->assertSame('exempt', $discountArrival->posting_status);
        $this->assertNull($discountArrival->warehouse_stock_lot_id);

        // 5. Verify created stock lot
        $lot = WarehouseStockLot::query()->find($productArrival->warehouse_stock_lot_id);
        $this->assertNotNull($lot);
        $this->assertSame('20.000', (string) $lot->quantity_received);
        $this->assertSame('2026-08-15', $lot->received_at->toDateString());
        $this->assertSame($uploadCompletionDate->toDateTimeString(), $lot->confirmed_at->toDateTimeString());
        $this->assertSame($user->getKey(), $lot->confirmed_by_user_id);
        $this->assertSame('automatic_upload', $lot->posting_provenance);
        $this->assertSame(WarehouseDateQuality::Confirmed, $lot->received_date_quality);
        $this->assertSame(WarehouseStockSource::Arrival, $lot->source_type);

        // 6. Verify item resolution
        $item = $lot->item;
        $this->assertNotNull($item);
        $this->assertSame('SKU-AUTO-1', $item->sku_number);

        // 7. Verify Idempotency: Re-syncing does not create duplicate stock
        $lotCountBefore = WarehouseStockLot::query()->count();
        app(PurchaseOrderLinker::class)->syncExtraction($extraction);
        $this->assertSame($lotCountBefore, WarehouseStockLot::query()->count());
    }

    public function test_historical_upload_does_not_auto_post_stock(): void
    {
        $normalizer = app(PurchaseOrderDataNormalizer::class);

        $po = PoExtraction::query()->create([
            'source_type' => 'google_sheet',
            'sheet_slug' => 'po_master',
            'po_number' => 'PO-HIST-200',
            'po_number_normalized' => $normalizer->normalizeIdentifier('PO-HIST-200'),
            'vendor_name' => 'Historical Vendor',
            'source_status' => 'Confirmed',
            'status_normalized' => 'confirmed',
            'arrival_status' => PurchaseOrderArrivalStatus::Pending,
        ]);

        $po->items()->create([
            'sort_order' => 1,
            'source_line_id' => 'po:pohist200:line:1',
            'item_code' => 'SKU-HIST-1',
            'product_description' => 'Historical Item',
            'quantity' => '100',
            'unit' => 'pcs',
        ]);

        $user = User::factory()->create();
        $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

        $upload = ReceivingUpload::query()->create([
            'submission_id' => fake()->uuid(),
            'upload_type_id' => $type->getKey(),
            'uploader_user_id' => $user->getKey(),
            'uploader_email' => $user->email,
            'r2_bucket' => 'test',
            'r2_prefix' => 'receiving/test',
            'file_count' => 1,
            'is_historical' => true, // HISTORICAL
        ]);

        $file = UploadedFile::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'original_file_name' => 'historical.pdf',
            'sanitized_file_name' => 'historical.pdf',
            'stored_file_name' => 'historical.pdf',
            'file_extension' => 'pdf',
            'r2_bucket' => 'test',
            'r2_object_key' => 'receiving/historical.pdf',
            'r2_staging_object_key' => 'staging/historical.pdf',
            'original_file_size' => 100,
            'final_file_size' => 100,
            'declared_content_type' => 'application/pdf',
            'content_type' => 'application/pdf',
        ]);

        $extraction = AiExtraction::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'uploaded_file_id' => $file->getKey(),
            'document_type' => 'Invoice',
            'raw_extracted_json' => [
                'document_type' => 'Invoice',
                'fields' => [
                    ['label' => 'PO Number', 'value' => 'PO-HIST-200'],
                    ['label' => 'Company Name', 'value' => 'Historical Vendor'],
                ],
                'items' => [
                    ['itemCode' => 'SKU-HIST-1', 'description' => 'Historical Item', 'quantity' => '100'],
                ],
            ],
        ]);

        app(PurchaseOrderLinker::class)->syncExtraction($extraction);

        // Arrivals should exist but stock lot should NOT be created
        $arrival = PurchaseOrderItemArrival::query()->where('receiving_upload_id', $upload->getKey())->first();
        $this->assertNotNull($arrival);
        $this->assertSame('pending', $arrival->posting_status);
        $this->assertNull($arrival->warehouse_stock_lot_id);
        $this->assertSame(0, WarehouseStockLot::query()->count());
    }

    public function test_reprocessor_blocks_reprocessing_when_stock_has_already_been_posted(): void
    {
        $normalizer = app(PurchaseOrderDataNormalizer::class);

        $po = PoExtraction::query()->create([
            'source_type' => 'google_sheet',
            'sheet_slug' => 'po_master',
            'po_number' => 'PO-REPROCESS-LOCK',
            'po_number_normalized' => $normalizer->normalizeIdentifier('PO-REPROCESS-LOCK'),
            'vendor_name' => 'Acme Supplier',
            'source_status' => 'Confirmed',
            'status_normalized' => 'confirmed',
            'arrival_status' => PurchaseOrderArrivalStatus::Pending,
        ]);

        $po->items()->create([
            'sort_order' => 1,
            'source_line_id' => 'po:poreprocesslock:line:1',
            'item_code' => 'SKU-LOCK-1',
            'product_description' => 'Locked Item',
            'quantity' => '10',
            'unit' => 'pcs',
        ]);

        $user = User::factory()->create();
        $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

        $upload = ReceivingUpload::query()->create([
            'submission_id' => fake()->uuid(),
            'upload_type_id' => $type->getKey(),
            'uploader_user_id' => $user->getKey(),
            'uploader_email' => $user->email,
            'r2_bucket' => 'test',
            'r2_prefix' => 'receiving/test',
            'file_count' => 1,
            'is_historical' => false,
            'ai_status' => AiStatus::Extracted,
        ]);

        $file = UploadedFile::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'original_file_name' => 'locked.pdf',
            'sanitized_file_name' => 'locked.pdf',
            'stored_file_name' => 'locked.pdf',
            'file_extension' => 'pdf',
            'r2_bucket' => 'test',
            'r2_object_key' => 'receiving/locked.pdf',
            'r2_staging_object_key' => 'staging/locked.pdf',
            'original_file_size' => 100,
            'final_file_size' => 100,
            'declared_content_type' => 'application/pdf',
            'content_type' => 'application/pdf',
        ]);

        $extraction = AiExtraction::query()->create([
            'receiving_upload_id' => $upload->getKey(),
            'uploaded_file_id' => $file->getKey(),
            'document_type' => 'Invoice',
            'raw_extracted_json' => [
                'document_type' => 'Invoice',
                'fields' => [
                    ['label' => 'PO Number', 'value' => 'PO-REPROCESS-LOCK'],
                    ['label' => 'Company Name', 'value' => 'Acme Supplier'],
                ],
                'items' => [
                    ['itemCode' => 'SKU-LOCK-1', 'description' => 'Locked Item', 'quantity' => '10'],
                ],
            ],
        ]);

        app(PurchaseOrderLinker::class)->syncExtraction($extraction);

        // Verify stock lot was auto-posted
        $this->assertSame(1, WarehouseStockLot::query()->count());

        // Attempting to reprocess should fail with ValidationException
        $reprocessor = app(ReceivingUploadReprocessor::class);
        $request = new Request;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot reprocess this upload because stock has already been posted to the warehouse.');

        $reprocessor->queue($upload, $user, $request);
    }
}
