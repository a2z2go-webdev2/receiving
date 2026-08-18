<?php

use App\Features\Receiving\Services\PoExtractionStore;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Features\Warehouse\Services\WarehouseOperations;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderItemArrival;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Models\WarehouseProgressEvent;
use App\Models\WarehouseStockLot;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('confirms all items in a PO at once via bulk PO endpoint', function (): void {
    $operator = bulkPoOperator();
    bulkPoCreateLinkedArrivals('PO-BULK-001', '2026-07-01', [
        ['sku' => 'SKU-A', 'description' => 'Item A', 'ordered' => 10, 'arrived' => 8],
        ['sku' => 'SKU-B', 'description' => 'Item B', 'ordered' => 20, 'arrived' => 20],
    ]);

    expect(PurchaseOrderItemArrival::query()->count())->toBe(2);

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.arrivals.confirm-by-po'), [
            'po_number' => 'PO-BULK-001',
        ])
        ->assertSessionHas('status');

    $lots = WarehouseStockLot::query()->orderBy('id')->get();
    expect($lots)->toHaveCount(2)
        ->and((float) $lots[0]->quantity_received)->toBe(8.0)
        ->and((float) $lots[1]->quantity_received)->toBe(20.0)
        ->and($lots[0]->po_number)->toBe('PO-BULK-001')
        ->and($lots[1]->po_number)->toBe('PO-BULK-001')
        ->and(WarehouseProgressEvent::query()->where('aggregate_type', 'stock_lot')->count())->toBe(2);
});

it('applies shared lot number and notes to all items when confirming by PO', function (): void {
    $operator = bulkPoOperator();
    bulkPoCreateLinkedArrivals('PO-LOT-001', '2026-07-01', [
        ['sku' => 'SKU-L1', 'description' => 'Lot item 1', 'ordered' => 5, 'arrived' => 5],
        ['sku' => 'SKU-L2', 'description' => 'Lot item 2', 'ordered' => 10, 'arrived' => 10],
    ]);

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.arrivals.confirm-by-po'), [
            'po_number' => 'PO-LOT-001',
            'lot_number' => 'BATCH-42',
            'notes' => 'Received in good condition',
        ])
        ->assertSessionHas('status');

    $lots = WarehouseStockLot::query()->orderBy('id')->get();
    expect($lots)->toHaveCount(2)
        ->and($lots[0]->lot_number)->toBe('BATCH-42')
        ->and($lots[0]->notes)->toBe('Received in good condition')
        ->and($lots[1]->lot_number)->toBe('BATCH-42')
        ->and($lots[1]->notes)->toBe('Received in good condition');
});

it('rejects bulk PO confirmation when no pending arrivals exist for the PO number', function (): void {
    $operator = bulkPoOperator();

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.arrivals.confirm-by-po'), [
            'po_number' => 'PO-NONEXISTENT',
        ])
        ->assertSessionHasErrors('po_number');
});

it('is idempotent when confirming a PO that is already fully confirmed', function (): void {
    $operator = bulkPoOperator();
    $operations = app(WarehouseOperations::class);

    bulkPoCreateLinkedArrivals('PO-IDEM-001', '2026-07-01', [
        ['sku' => 'SKU-ID1', 'description' => 'Idempotent item', 'ordered' => 5, 'arrived' => 5],
    ]);

    // First confirmation
    $lots = $operations->confirmArrivalsByPo(['po_number' => 'PO-IDEM-001'], $operator);
    expect($lots)->toHaveCount(1);

    // Second confirmation should fail because no pending arrivals remain
    expect(fn () => $operations->confirmArrivalsByPo(['po_number' => 'PO-IDEM-001'], $operator))
        ->toThrow(ValidationException::class);

    expect(WarehouseStockLot::query()->count())->toBe(1);
});

it('only confirms pending items when some items in a PO are already confirmed', function (): void {
    $operator = bulkPoOperator();
    $operations = app(WarehouseOperations::class);

    bulkPoCreateLinkedArrivals('PO-PARTIAL-001', '2026-07-01', [
        ['sku' => 'SKU-P1', 'description' => 'Partial item 1', 'ordered' => 5, 'arrived' => 5],
        ['sku' => 'SKU-P2', 'description' => 'Partial item 2', 'ordered' => 10, 'arrived' => 10],
    ]);

    // Confirm just the first item individually
    $firstArrival = PurchaseOrderItemArrival::query()->orderBy('id')->first();
    $operations->confirmArrival($firstArrival, ['quantity_received' => 5], $operator);
    expect(WarehouseStockLot::query()->count())->toBe(1);

    // Now confirm by PO — only the second item should be confirmed
    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.arrivals.confirm-by-po'), [
            'po_number' => 'PO-PARTIAL-001',
        ])
        ->assertSessionHas('status');

    $lots = WarehouseStockLot::query()->orderBy('id')->get();
    expect($lots)->toHaveCount(2)
        ->and((float) $lots[0]->quantity_received)->toBe(5.0)
        ->and((float) $lots[1]->quantity_received)->toBe(10.0);
});

it('rejects bulk PO confirmation without required po_number', function (): void {
    $operator = bulkPoOperator();

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.arrivals.confirm-by-po'), [])
        ->assertSessionHasErrors('po_number');
});

it('provides PO-grouped data on the arrivals page', function (): void {
    $operator = bulkPoOperator();
    bulkPoCreateLinkedArrivals('PO-VIEW-001', '2026-07-01', [
        ['sku' => 'SKU-V1', 'description' => 'View item 1', 'ordered' => 5, 'arrived' => 5],
        ['sku' => 'SKU-V2', 'description' => 'View item 2', 'ordered' => 10, 'arrived' => 10],
    ]);
    bulkPoCreateLinkedArrivals('PO-VIEW-002', '2026-07-02', [
        ['sku' => 'SKU-V3', 'description' => 'View item 3', 'ordered' => 3, 'arrived' => 3],
    ]);

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.arrivals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('warehouse/arrivals')
            ->has('pendingPoGroups', 2)
            ->has('pendingPoGroups.0.items', 2)
            ->has('pendingPoGroups.1.items', 1)
            ->where('pendingPoGroups.0.pending_item_count', 2)
            ->where('pendingCount', 3));
});

it('marks item as received in PO details when received by item while keeping unreceived items pending', function (): void {
    $operator = bulkPoOperator();
    $operations = app(WarehouseOperations::class);

    bulkPoCreateLinkedArrivals('PO-STATUS-001', '2026-07-01', [
        ['sku' => 'SKU-S1', 'description' => 'Status item 1', 'ordered' => 5, 'arrived' => 5],
        ['sku' => 'SKU-S2', 'description' => 'Status item 2', 'ordered' => 10, 'arrived' => 10],
    ]);

    // Confirm Item 1 individually by item
    $firstArrival = PurchaseOrderItemArrival::query()->where('item_code', 'SKU-S1')->firstOrFail();
    $operations->confirmArrival($firstArrival, ['quantity_received' => 5, 'lot_number' => 'LOT-ITEM-1'], $operator);

    // Verify batch inventory (stock lot) created only for Item 1
    expect(WarehouseStockLot::query()->count())->toBe(1)
        ->and(WarehouseStockLot::query()->first()->lot_number)->toBe('LOT-ITEM-1');

    // Check PO details on arrivals page
    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.arrivals.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $page->component('warehouse/arrivals')
                ->has('pendingPoGroups', 1)
                ->where('pendingPoGroups.0.po_number', 'PO-STATUS-001')
                ->where('pendingPoGroups.0.item_count', 2)
                ->where('pendingPoGroups.0.pending_item_count', 1)
                ->where('pendingPoGroups.0.items.0.sku_number', 'SKU-S1')
                ->where('pendingPoGroups.0.items.0.is_received', true)
                ->where('pendingPoGroups.0.items.0.lot_number', 'LOT-ITEM-1')
                ->where('pendingPoGroups.0.items.1.sku_number', 'SKU-S2')
                ->where('pendingPoGroups.0.items.1.is_received', false);
        });

    // Receive remaining items by PO
    $operations->confirmArrivalsByPo(['po_number' => 'PO-STATUS-001', 'lot_number' => 'LOT-PO-2'], $operator);

    // Verify a second batch was created for Item 2
    expect(WarehouseStockLot::query()->count())->toBe(2);

    // PO is now fully received and should no longer be in pendingPoGroups
    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.arrivals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('warehouse/arrivals')
            ->has('pendingPoGroups', 0));
});

// ─── Helpers ───

function bulkPoOperator(): User
{
    $user = User::factory()->create();
    $user->assignRole('warehouse_operator');

    return $user;
}

function bulkPoCreateLinkedArrivals(string $poNumber, string $poDate, array $items): void
{
    $poItems = array_map(fn (array $item) => [
        'itemCode' => $item['sku'],
        'productDescription' => $item['description'],
        'quantity' => (string) $item['ordered'],
        'unit' => 'pc',
    ], $items);

    $invoiceItems = array_map(fn (array $item) => [
        'itemCode' => $item['sku'],
        'description' => $item['description'],
        'quantity' => (string) $item['arrived'],
        'unitPrice' => '10',
        'amount' => (string) ($item['arrived'] * 10),
    ], $items);

    $poExtraction = bulkPoStoredPurchaseOrder($poNumber, $poDate, $poItems);
    $invoice = bulkPoExtraction('a2z2go', "invoice-{$poNumber}-".fake()->uuid().'.pdf', bulkPoInvoiceData($poNumber, $poDate, $invoiceItems));

    app(PurchaseOrderLinker::class)->syncExtraction($invoice);
}

function bulkPoStoredPurchaseOrder(string $poNumber, string $poDate, array $items): PoExtraction
{
    $extraction = bulkPoExtraction('purchase-order', "po-{$poNumber}-".fake()->uuid().'.pdf', [
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

function bulkPoExtraction(string $uploadTypeSlug, string $fileName, array $data): AiExtraction
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

function bulkPoInvoiceData(string $poNumber, string $poDate, array $items): array
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
