<?php

use App\Enums\WarehouseDeliveryStatus;
use App\Features\Warehouse\Services\WarehouseOperations;
use App\Models\WarehouseDelivery;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('creates multiple customer deliveries in a single truck shipment request', function (): void {
    $operator = warehouseOperator();
    $operations = app(WarehouseOperations::class);

    $stock = openingStock($operations, $operator, 'ITEM-001', 'Test Item A', 500, '2026-07-01');

    $payload = [
        'dispatch_immediately' => false,
        'deliveries' => [
            [
                'customer_name' => 'Acme Logistics',
                'sales_order' => 'SO-101',
                'po' => 'PO-CUST-1',
                'notes' => 'First customer delivery on truck',
                'lines' => [
                    [
                        'warehouse_item_id' => $stock->warehouse_item_id,
                        'quantity' => '100',
                    ],
                ],
            ],
            [
                'customer_name' => 'Beta Retailers',
                'sales_order' => 'SO-102',
                'po' => 'PO-CUST-2',
                'notes' => 'Second customer delivery on truck',
                'lines' => [
                    [
                        'warehouse_item_id' => $stock->warehouse_item_id,
                        'quantity' => '150',
                    ],
                ],
            ],
        ],
    ];

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.deliveries.store-bulk'), $payload)
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(WarehouseDelivery::query()->count())->toBe(2);

    $delivery1 = WarehouseDelivery::query()->where('customer_name', 'Acme Logistics')->firstOrFail();
    $delivery2 = WarehouseDelivery::query()->where('customer_name', 'Beta Retailers')->firstOrFail();

    expect($delivery1->status)->toBe(WarehouseDeliveryStatus::Draft)
        ->and($delivery2->status)->toBe(WarehouseDeliveryStatus::Draft);
});

it('creates and immediately dispatches multiple customer deliveries for a truck run', function (): void {
    $operator = warehouseOperator();
    $operations = app(WarehouseOperations::class);

    $stock = openingStock($operations, $operator, 'ITEM-002', 'Test Item B', 1000, '2026-07-01');

    $payload = [
        'dispatch_immediately' => true,
        'deliveries' => [
            [
                'customer_name' => 'Global Freight',
                'sales_order' => 'SO-201',
                'po' => 'PO-201',
                'lines' => [
                    [
                        'warehouse_item_id' => $stock->warehouse_item_id,
                        'quantity' => '200',
                    ],
                ],
            ],
            [
                'customer_name' => 'Direct Express',
                'sales_order' => 'SO-202',
                'po' => 'PO-202',
                'lines' => [
                    [
                        'warehouse_item_id' => $stock->warehouse_item_id,
                        'quantity' => '300',
                    ],
                ],
            ],
        ],
    ];

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.deliveries.store-bulk'), $payload)
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(WarehouseDelivery::query()->where('status', WarehouseDeliveryStatus::Dispatched)->count())->toBe(2);
});

it('dispatches multiple draft deliveries in bulk for a truck run', function (): void {
    $operator = warehouseOperator();
    $operations = app(WarehouseOperations::class);

    $stock = openingStock($operations, $operator, 'ITEM-003', 'Test Item C', 1000, '2026-07-01');

    $del1 = $operations->createDelivery([
        'customer_name' => 'Customer Alpha',
        'sales_order' => 'SO-301',
        'po' => 'PO-301',
        'lines' => [['warehouse_item_id' => $stock->warehouse_item_id, 'quantity' => '100']],
    ], $operator);

    $del2 = $operations->createDelivery([
        'customer_name' => 'Customer Bravo',
        'sales_order' => 'SO-302',
        'po' => 'PO-302',
        'lines' => [['warehouse_item_id' => $stock->warehouse_item_id, 'quantity' => '200']],
    ], $operator);

    $this->actingAs($operator)
        ->withSession(['warehouse.otp_verified_at' => now()->getTimestamp()])
        ->post(route('warehouse.deliveries.dispatch-bulk'), [
            'delivery_ids' => [$del1->id, $del2->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($del1->refresh()->status)->toBe(WarehouseDeliveryStatus::Dispatched)
        ->and($del2->refresh()->status)->toBe(WarehouseDeliveryStatus::Dispatched);
});
