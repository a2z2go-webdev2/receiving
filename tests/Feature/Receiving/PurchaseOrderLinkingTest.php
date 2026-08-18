<?php

use App\Enums\Permission;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkSource;
use App\Enums\PurchaseOrderLinkStatus;
use App\Features\Receiving\Services\PoExtractionStore;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Features\Warehouse\Services\WarehouseOperations;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\PurchaseOrderItemArrival;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Models\WarehouseStockLot;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('auto-links an invoice uploaded after its purchase order and records arrived quantities', function (): void {
    $schedule = poLinkSchedule('SKU-1', 'Paper cups', 10, 'case', 1);
    $po = poLinkStoredPurchaseOrder('PO-2026-0701', '2026-07-03', [
        ['itemCode' => 'SKU-1', 'productDescription' => 'Paper cups', 'quantity' => '10', 'unit' => 'case'],
    ]);
    $invoice = poLinkExtraction('a2z2go', 'invoice.pdf', poLinkInvoiceData('PO 2026 0701', '[See image]', [
        ['description' => 'Paper cups', 'quantity' => '8', 'unitPrice' => '10', 'amount' => '80'],
    ]));

    app(PurchaseOrderLinker::class)->syncExtraction($invoice);

    $invoice->refresh();
    $po->refresh();
    $arrival = PurchaseOrderItemArrival::query()->firstOrFail();

    expect(PurchaseOrderDocumentLink::query()->count())->toBe(1)
        ->and($invoice->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($invoice->po_date)->toBe('2026-07-03')
        ->and(poLinkFieldValue($invoice->raw_extracted_json, 'PO Date'))->toBe('2026-07-03')
        ->and($po->arrival_status)->toBe(PurchaseOrderArrivalStatus::Arrived)
        ->and($arrival->purchase_order_item_schedule_id)->toBe($schedule->getKey())
        ->and((float) $arrival->arrived_quantity)->toBe(8.0)
        ->and((float) $arrival->ordered_quantity)->toBe(10.0)
        ->and($arrival->status)->toBe('short');
});

it('keeps a confirmed warehouse receipt idempotent when reconciliation rebuilds arrival projections', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15 09:30:00'));

    poLinkStoredPurchaseOrder('PO-WAREHOUSE-1', '2026-07-01', [
        ['itemCode' => 'SKU-WH-1', 'productDescription' => 'Warehouse item', 'quantity' => '10', 'unit' => 'case'],
    ]);
    $invoice = poLinkExtraction('a2z2go', 'warehouse-invoice.pdf', poLinkInvoiceData('PO-WAREHOUSE-1', '2026-07-01', [
        ['itemCode' => 'SKU-WH-1', 'description' => 'Warehouse item', 'quantity' => '10', 'unit' => 'case'],
    ]));
    app(PurchaseOrderLinker::class)->syncExtraction($invoice);
    $arrival = PurchaseOrderItemArrival::query()->sole();
    $operator = User::factory()->create();
    $operator->assignRole('warehouse_operator');

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.arrivals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('warehouse/arrivals')
            ->where('pendingArrivals.data.0.po_date', '2026-07-01')
            ->where('pendingArrivals.data.0.ordered_quantity', 10)
            ->where('pendingArrivals.data.0.supplier_delivered_quantity', 10)
            ->where('pendingArrivals.data.0.supplier_delivery_date', '2026-07-15')
            ->where('pendingArrivals.data.0.po_waiting_days', 14));

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.arrivals.confirm', $arrival), [
            'quantity_received' => 10,
            'received_at' => '1999-01-01',
            'received_date_quality' => 'estimated',
        ])
        ->assertSessionHas('status');

    $lot = WarehouseStockLot::query()->sole();
    expect($lot->received_at?->toDateString())->toBe('2026-07-15')
        ->and($lot->received_date_quality->value)->toBe('confirmed')
        ->and($lot->confirmed_at->toDateTimeString())->toBe('2026-07-15 09:30:00');

    app(PurchaseOrderLinker::class)->syncExtraction($invoice);
    $rebuiltArrival = PurchaseOrderItemArrival::query()->sole();
    $retry = app(WarehouseOperations::class)->confirmArrival($rebuiltArrival, [
        'quantity_received' => 10,
    ], $operator);

    expect($arrival->source_key)->toBe('ai:'.$invoice->getKey().':line:0')
        ->and($rebuiltArrival->source_key)->toBe($arrival->source_key)
        ->and($retry->getKey())->toBe($lot->getKey())
        ->and(WarehouseStockLot::query()->count())->toBe(1)
        ->and((float) $lot->quantity_received)->toBe(10.0);

    $this->travelBack();
});

it('keeps document arrivals visible when unrelated opening stock exists', function (): void {
    poLinkStoredPurchaseOrder('PO-PENDING-AFTER-OPENING', '2026-07-01', [
        ['itemCode' => 'SKU-PENDING', 'productDescription' => 'Pending warehouse item', 'quantity' => '10', 'unit' => 'case'],
    ]);
    $invoice = poLinkExtraction('a2z2go', 'pending-after-opening.pdf', poLinkInvoiceData('PO-PENDING-AFTER-OPENING', '2026-07-01', [
        ['itemCode' => 'SKU-PENDING', 'description' => 'Pending warehouse item', 'quantity' => '10', 'unit' => 'case'],
    ]));
    app(PurchaseOrderLinker::class)->syncExtraction($invoice);
    $arrival = PurchaseOrderItemArrival::query()->sole();
    $operator = User::factory()->create();
    $operator->assignRole('warehouse_operator');

    app(WarehouseOperations::class)->addOpeningStock([
        'sku_number' => 'SKU-OPENING',
        'description' => 'Pre-existing stock',
        'unit' => 'case',
        'quantity_received' => 5,
        'received_at' => null,
        'received_date_quality' => 'unknown',
    ], $operator);

    expect(WarehouseStockLot::query()->sole()->source_key)->toStartWith('opening:');

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.arrivals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('warehouse/arrivals')
            ->where('pendingCount', 1)
            ->where('pendingArrivals.data.0.id', $arrival->getKey()));
});

it('auto-links an older invoice when the matching purchase order is uploaded later', function (): void {
    $invoice = poLinkExtraction('bonita', 'receipt.pdf', poLinkInvoiceData('PO-LATE-1', '[See image]', []));

    app(PurchaseOrderLinker::class)->syncExtraction($invoice);
    expect($invoice->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::AwaitingPurchaseOrder);

    $po = poLinkStoredPurchaseOrder('PO LATE 1', '2026-07-04', []);

    $invoice->refresh();
    expect(PurchaseOrderDocumentLink::query()->count())->toBe(1)
        ->and($invoice->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($invoice->po_date)->toBe('2026-07-04')
        ->and($po->refresh()->arrival_status)->toBe(PurchaseOrderArrivalStatus::Arrived);
});

it('manually links an invoice without a po number and fills missing po fields', function (): void {
    $admin = poLinkAdmin();
    $po = poLinkStoredPurchaseOrder('PO-MANUAL-1', '2026-07-05', []);
    $invoice = poLinkExtraction('keysys', 'invoice-no-po.pdf', poLinkInvoiceData('[See image]', '[See image]', []));

    app(PurchaseOrderLinker::class)->syncExtraction($invoice);
    expect($invoice->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::MissingPoNumber);

    $link = app(PurchaseOrderLinker::class)->link($invoice, $po, $admin, PurchaseOrderLinkSource::Manual);

    $invoice->refresh();
    expect($link->source)->toBe(PurchaseOrderLinkSource::Manual)
        ->and($invoice->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($invoice->po_number)->toBe('PO-MANUAL-1')
        ->and($invoice->po_date)->toBe('2026-07-05')
        ->and(poLinkFieldValue($invoice->raw_extracted_json, 'PO Number'))->toBe('PO-MANUAL-1');
});

it('rejects manual links when the invoice po number does not match the selected purchase order', function (): void {
    $admin = poLinkAdmin();
    $po = poLinkStoredPurchaseOrder('PO-OTHER', '2026-07-05', []);
    $invoice = poLinkExtraction('pingcon', 'invoice.pdf', poLinkInvoiceData('PO-WANTED', '2026-07-05', []));

    expect(fn () => app(PurchaseOrderLinker::class)->link($invoice, $po, $admin))
        ->toThrow(ValidationException::class);

    expect(PurchaseOrderDocumentLink::query()->count())->toBe(0);
});

it('links multiple invoice or receipt uploads to one purchase order without double counting on resync', function (): void {
    poLinkSchedule('SKU-MULTI', 'Split delivery cups', 10, 'case', 1);
    $po = poLinkStoredPurchaseOrder('PO-MULTI-ARRIVAL', '2026-07-05', [
        ['itemCode' => 'SKU-MULTI', 'productDescription' => 'Split delivery cups', 'quantity' => '10', 'unit' => 'case'],
    ]);
    $first = poLinkExtraction('a2z2go', 'first.pdf', poLinkInvoiceData('PO-MULTI-ARRIVAL', '[See image]', [
        ['description' => 'Split delivery cups', 'quantity' => '4', 'unit' => 'case'],
    ]));
    $second = poLinkExtraction('a2z2go', 'second.pdf', poLinkInvoiceData('PO-MULTI-ARRIVAL', '[See image]', [
        ['description' => 'Split delivery cups', 'quantity' => '6', 'unit' => 'case'],
    ]));

    app(PurchaseOrderLinker::class)->syncExtraction($first);
    app(PurchaseOrderLinker::class)->syncExtraction($second);

    expect(PurchaseOrderDocumentLink::query()->active()->count())->toBe(2)
        ->and(PurchaseOrderItemArrival::query()->count())->toBe(2)
        ->and((float) PurchaseOrderItemArrival::query()->sum('arrived_quantity'))->toBe(10.0)
        ->and($first->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($second->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked)
        ->and($po->refresh()->arrival_status)->toBe(PurchaseOrderArrivalStatus::Arrived);

    app(PurchaseOrderLinker::class)->syncExtraction($first);

    expect(PurchaseOrderDocumentLink::query()->active()->count())->toBe(2)
        ->and(PurchaseOrderItemArrival::query()->count())->toBe(2)
        ->and((float) PurchaseOrderItemArrival::query()->sum('arrived_quantity'))->toBe(10.0);
});

it('links and unlinks from the admin upload detail route', function (): void {
    $admin = poLinkAdmin();
    $po = poLinkStoredPurchaseOrder('PO-ROUTE-1', '2026-07-05', []);
    $invoice = poLinkExtraction('a2z2go', 'route.pdf', poLinkInvoiceData('[See image]', '[See image]', []));
    $upload = $invoice->upload;

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.purchase-order-link.store', [$upload, $invoice]), [
            'po_extraction_id' => $po->getKey(),
        ])
        ->assertSessionHas('status');

    expect($invoice->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::Linked);

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.uploads.purchase-order-link.destroy', [$upload, $invoice]))
        ->assertSessionHas('status');

    expect($invoice->refresh()->po_link_status)->toBe(PurchaseOrderLinkStatus::ReadyToLink)
        ->and($po->refresh()->arrival_status)->toBe(PurchaseOrderArrivalStatus::Pending);
});

it('requires operations permission to manually link or unlink purchase orders', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        Permission::AccessAdmin->value,
        Permission::ViewUploads->value,
    ]);
    $operator = poLinkAdmin();
    $po = poLinkStoredPurchaseOrder('PO-ROUTE-AUTH', '2026-07-05', []);
    $invoice = poLinkExtraction('a2z2go', 'route-auth.pdf', poLinkInvoiceData('[See image]', '[See image]', []));
    $upload = $invoice->upload;

    $this->actingAs($viewer)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.purchase-order-link.store', [$upload, $invoice]), [
            'po_extraction_id' => $po->getKey(),
        ])
        ->assertForbidden();

    expect(PurchaseOrderDocumentLink::query()->count())->toBe(0);

    $link = app(PurchaseOrderLinker::class)->link($invoice, $po, $operator, PurchaseOrderLinkSource::Manual);

    $this->actingAs($viewer)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->delete(route('admin.uploads.purchase-order-link.destroy', [$upload, $invoice]))
        ->assertForbidden();

    expect($link->refresh()->unlinked_at)->toBeNull();
});

function poLinkStoredPurchaseOrder(string $poNumber, string $poDate, array $items): PoExtraction
{
    $extraction = poLinkExtraction('purchase-order', 'po.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [],
        'items' => [],
    ]);

    return app(PoExtractionStore::class)->store($extraction, [
        'document_type' => 'Purchase Order',
        'fields' => [
            ['label' => 'PO Number', 'value' => $poNumber],
            ['label' => 'PO Reference', 'value' => '[See image]'],
            ['label' => 'PO Date', 'value' => $poDate],
            ['label' => 'Buyer Company', 'value' => 'A2Z2GO'],
            ['label' => 'Vendor Name', 'value' => 'Acme Supplier'],
            ['label' => 'Contact Person', 'value' => 'Jane'],
            ['label' => 'Payment Terms', 'value' => 'COD'],
        ],
        'items' => $items,
    ]);
}

function poLinkExtraction(string $uploadTypeSlug, string $fileName, array $data): AiExtraction
{
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', $uploadTypeSlug)->firstOrFail();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $user->getKey(),
        'uploader_email' => $user->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
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
    ]);

    return AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => $data['document_type'],
        'raw_extracted_json' => $data,
        'corrected_json' => null,
    ]);
}

function poLinkSchedule(
    string $sku,
    string $description,
    float $targetQuantity,
    string $unit,
    ?int $week = null,
): PurchaseOrderItemSchedule {
    $normalizer = app(PurchaseOrderDataNormalizer::class);

    return PurchaseOrderItemSchedule::query()->create([
        'sku_number' => $sku,
        'sku_number_normalized' => $normalizer->normalizeIdentifier($sku),
        'description' => $description,
        'description_normalized' => $normalizer->normalizeDescription($description),
        'target_quantity' => $normalizer->decimalString($targetQuantity),
        'unit' => $unit,
        'expected_week' => null,
        'is_special_order' => false,
        'is_active' => true,
    ]);
}

function poLinkInvoiceData(string $poNumber, string $poDate, array $items): array
{
    return [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Acme Supplier'],
            ['label' => 'PO Number', 'value' => $poNumber],
            ['label' => 'PO Date', 'value' => $poDate],
        ],
        'items' => $items,
    ];
}

function poLinkFieldValue(?array $data, string $label): ?string
{
    $fields = $data['fields'] ?? null;
    if (! is_array($fields)) {
        return null;
    }

    foreach ($fields as $field) {
        if (is_array($field) && ($field['label'] ?? null) === $label) {
            return (string) ($field['value'] ?? '');
        }
    }

    return null;
}

function poLinkAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}
