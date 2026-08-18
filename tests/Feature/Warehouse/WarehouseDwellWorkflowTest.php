<?php

use App\Enums\Permission;
use App\Enums\WarehouseDeliveryStatus;
use App\Features\Warehouse\Services\WarehouseDwellReport;
use App\Features\Warehouse\Services\WarehouseOperations;
use App\Models\User;
use App\Models\WarehouseAllocation;
use App\Models\WarehouseDelivery;
use App\Models\WarehouseItem;
use App\Models\WarehouseProgressEvent;
use App\Models\WarehouseStockLot;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

it('requires authentication for warehouse operations and reports', function (): void {
    $this->post(route('warehouse.opening-stock.store'), [])->assertRedirect(route('login'));
    $this->get(route('warehouse.arrivals.index'))->assertRedirect(route('login'));
    $this->get(route('warehouse.inventory.index'))->assertRedirect(route('login'));
    $this->get(route('warehouse.deliveries.index'))->assertRedirect(route('login'));
    $this->get(route('admin.purchase-orders.reports.warehouse-dwell'))->assertRedirect(route('login'));
});

it('gives warehouse operators their own dashboard and keeps mutations away from other roles', function (): void {
    $operator = warehouseOperator();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $uploader = User::factory()->create();
    $uploader->assignRole('uploader');

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('dashboard'))
        ->assertRedirect(route('warehouse.dashboard'));

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('warehouse/dashboard'));

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.arrivals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('warehouse/arrivals'));

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.inventory.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('warehouse/inventory'));

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.deliveries.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('warehouse/deliveries'));

    expect($operator->can(Permission::ViewWarehouseReports->value))->toBeFalse();

    $this->actingAs($operator)
        ->get(route('admin.purchase-orders.reports.warehouse-dwell'))
        ->assertForbidden();

    $payload = [
        'description' => 'Downy fabric conditioner',
        'sku_number' => 'DOWNY-001',
        'unit' => 'pc',
        'quantity_received' => '1000',
        'received_at' => '2026-07-01',
        'received_date_quality' => 'confirmed',
    ];

    $this->actingAs($admin)
        ->post(route('warehouse.opening-stock.store'), $payload)
        ->assertForbidden();

    $this->actingAs($uploader)
        ->post(route('warehouse.opening-stock.store'), $payload)
        ->assertForbidden();

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.opening-stock.store'), $payload)
        ->assertSessionHas('status');

    $lot = WarehouseStockLot::query()->sole();
    expect($lot->confirmed_by_user_id)->toBe($operator->getKey())
        ->and((float) $lot->quantity_received)->toBe(1000.0)
        ->and(WarehouseProgressEvent::query()->where('aggregate_type', 'stock_lot')->count())->toBe(1);
});

it('lets administrators read the dwell report without granting warehouse mutations', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.reports.warehouse-dwell', ['from' => '2026-07-01', 'to' => '2026-07-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/reports/warehouse-dwell')
            ->where('backHref', '/admin/purchase-orders/reports'));

    $this->actingAs($admin)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->get(route('warehouse.dashboard'))
        ->assertForbidden();
});

it('allocates mixed old and new stock by FIFO and calculates quantity-weighted dwell', function (): void {
    $operator = warehouseOperator();
    $operations = app(WarehouseOperations::class);

    $oldLot = openingStock($operations, $operator, 'DOWNY-001', 'Downy fabric conditioner', 300, '2026-07-01');
    $newLot = openingStock($operations, $operator, 'DOWNY-001', 'Downy fabric conditioner', 1000, '2026-07-05');
    expect($oldLot->warehouse_item_id)->toBe($newLot->warehouse_item_id);

    $delivery = customerDelivery($operations, $operator, $oldLot->item, 500);
    $operations->dispatch($delivery, '2026-07-06', $operator);
    $operations->deliver($delivery, '2026-07-07', null, $operator);

    $allocations = WarehouseAllocation::query()->orderBy('id')->get();
    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->warehouse_stock_lot_id)->toBe($oldLot->getKey())
        ->and((float) $allocations[0]->quantity_allocated)->toBe(300.0)
        ->and($allocations[1]->warehouse_stock_lot_id)->toBe($newLot->getKey())
        ->and((float) $allocations[1]->quantity_allocated)->toBe(200.0)
        ->and($delivery->refresh()->status)->toBe(WarehouseDeliveryStatus::Delivered);

    $result = app(WarehouseDwellReport::class)->build(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
    );
    $row = $result['rows']->items()[0];

    expect($row['warehouse_holding_days'])->toBe(3.4)
        ->and($row['warehouse_dwell_days'])->toBe(4.4)
        ->and($row['maximum_warehouse_dwell_days'])->toBe(6)
        ->and($row['date_coverage_percent'])->toBe(100.0)
        ->and($result['summary']['average_line_warehouse_holding_days'])->toBe(3.4)
        ->and($result['summary']['average_line_warehouse_dwell_days'])->toBe(4.4);
});

it('does not invent arrival dates for opening stock and reports partial date coverage', function (): void {
    $operator = warehouseOperator();
    $operations = app(WarehouseOperations::class);

    $unknownLot = openingStock($operations, $operator, 'SKU-MIXED', 'Mixed-date item', 300, null);
    $knownLot = openingStock($operations, $operator, 'SKU-MIXED', 'Mixed-date item', 200, '2026-07-01');
    $delivery = customerDelivery($operations, $operator, $unknownLot->item, 500);

    $operations->dispatch($delivery, '2026-07-06', $operator);
    $operations->deliver($delivery, '2026-07-06', null, $operator);

    $allocations = WarehouseAllocation::query()->orderBy('id')->get();
    expect($allocations[0]->warehouse_stock_lot_id)->toBe($unknownLot->getKey())
        ->and($allocations[1]->warehouse_stock_lot_id)->toBe($knownLot->getKey());

    $result = app(WarehouseDwellReport::class)->build(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
    );
    $row = $result['rows']->items()[0];

    expect($row['warehouse_holding_days'])->toBe(5.0)
        ->and($row['warehouse_dwell_days'])->toBe(5.0)
        ->and($row['date_coverage_percent'])->toBe(40.0)
        ->and($result['summary']['fully_dated_lines'])->toBe(0);
});

it('rolls back all FIFO allocations when any consolidated delivery item is short', function (): void {
    $operator = warehouseOperator();
    $operations = app(WarehouseOperations::class);
    $first = openingStock($operations, $operator, 'SKU-ENOUGH', 'Enough item', 100, '2026-07-01')->item;
    $second = openingStock($operations, $operator, 'SKU-SHORT', 'Short item', 5, '2026-07-01')->item;
    $delivery = $operations->createDelivery([
        'customer_name' => 'Consolidated customer',
        'lines' => [
            ['warehouse_item_id' => $first->getKey(), 'quantity' => 50],
            ['warehouse_item_id' => $second->getKey(), 'quantity' => 10],
        ],
    ], $operator);

    expect(fn () => $operations->dispatch($delivery, '2026-07-06', $operator))
        ->toThrow(ValidationException::class);

    expect(WarehouseAllocation::query()->count())->toBe(0)
        ->and($delivery->refresh()->status)->toBe(WarehouseDeliveryStatus::Draft)
        ->and($delivery->dispatched_at)->toBeNull();
});

it('excludes future stock, rejects reversed dates, and makes progress retries idempotent', function (): void {
    $operator = warehouseOperator();
    $operations = app(WarehouseOperations::class);
    $lot = openingStock($operations, $operator, 'SKU-FUTURE', 'Future stock', 10, '2026-07-10');
    $delivery = customerDelivery($operations, $operator, $lot->item, 10);

    expect(fn () => $operations->dispatch($delivery, '2026-07-09', $operator))
        ->toThrow(ValidationException::class);
    expect(WarehouseAllocation::query()->count())->toBe(0);

    $operations->dispatch($delivery, '2026-07-10', $operator);
    $operations->dispatch($delivery, '2026-07-10', $operator);
    expect(WarehouseAllocation::query()->count())->toBe(1)
        ->and(WarehouseProgressEvent::query()->where('aggregate_type', 'delivery')->where('to_status', 'dispatched')->count())->toBe(1);

    expect(fn () => $operations->deliver($delivery, '2026-07-09', null, $operator))
        ->toThrow(ValidationException::class, 'Delivery date cannot be before the dispatch date.');

    $operations->deliver($delivery, '2026-07-11', null, $operator);
    $operations->deliver($delivery, '2026-07-11', null, $operator);
    expect($delivery->refresh()->status)->toBe(WarehouseDeliveryStatus::Delivered)
        ->and(WarehouseProgressEvent::query()->where('aggregate_type', 'delivery')->where('to_status', 'delivered')->count())->toBe(1);
});

it('rejects malformed opening stock and duplicate delivery items at the request boundary', function (): void {
    $operator = warehouseOperator();
    $item = warehouseItem('SKU-VALIDATION', 'Validation item');

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.opening-stock.store'), [
            'description' => 'Invalid unknown date',
            'quantity_received' => 10,
            'received_date_quality' => 'unknown',
            'received_at' => '2026-07-01',
        ])
        ->assertSessionHasErrors('received_at');

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.deliveries.store'), [
            'customer_name' => 'Duplicate customer',
            'lines' => [
                ['warehouse_item_id' => $item->getKey(), 'quantity' => 1],
                ['warehouse_item_id' => $item->getKey(), 'quantity' => 2],
            ],
        ])
        ->assertSessionHasErrors('lines.1.warehouse_item_id');

    expect(WarehouseDelivery::query()->count())->toBe(0);
});

function warehouseOperator(): User
{
    $user = User::factory()->create();
    $user->assignRole('warehouse_operator');

    return $user;
}

function warehouseItem(string $sku, string $description): WarehouseItem
{
    return WarehouseItem::query()->create([
        'identity_key' => 'sku:'.strtolower($sku),
        'sku_number' => $sku,
        'sku_number_normalized' => strtolower($sku),
        'description' => $description,
        'description_normalized' => strtolower($description),
        'base_unit' => 'pc',
    ]);
}

function openingStock(
    WarehouseOperations $operations,
    User $operator,
    string $sku,
    string $description,
    int|float $quantity,
    ?string $receivedAt,
): WarehouseStockLot {
    return $operations->addOpeningStock([
        'sku_number' => $sku,
        'description' => $description,
        'unit' => 'pc',
        'quantity_received' => $quantity,
        'received_at' => $receivedAt,
        'received_date_quality' => $receivedAt === null ? 'unknown' : 'confirmed',
    ], $operator);
}

function customerDelivery(
    WarehouseOperations $operations,
    User $operator,
    WarehouseItem $item,
    int|float $quantity,
): WarehouseDelivery {
    return $operations->createDelivery([
        'customer_name' => 'Customer A',
        'delivery_reference' => 'DEL-001',
        'lines' => [['warehouse_item_id' => $item->getKey(), 'quantity' => $quantity]],
    ], $operator);
}
