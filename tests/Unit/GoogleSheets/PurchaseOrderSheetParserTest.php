<?php

use App\Services\GoogleSheets\PurchaseOrderSheetParser;

test('PurchaseOrderSheetParser groups rows by PO number, validates headers, and classifies adjustments', function () {
    /** @var PurchaseOrderSheetParser $parser */
    $parser = app(PurchaseOrderSheetParser::class);

    $rows = [
        [
            'Supplier' => 'Nestle Philippines',
            'Type' => 'Purchase Order',
            'Status' => 'Confirmed',
            'P.O no.' => 'PO-001234',
            'Date' => '2026-09-15',
            'Net total' => '10,000.00',
            'VAT total' => '1,200.00',
            'Total sum' => '11,200.00',
            'Notes' => 'Delivery to Warehouse 1',
            'Code' => 'NES-MILO-1KG',
            'Name' => 'Milo Choco Malt 1kg',
            'Amount' => '50',
            'Unit price' => '200.00',
        ],
        [
            'Supplier' => 'Nestle Philippines',
            'Type' => 'Purchase Order',
            'Status' => 'Confirmed',
            'P.O no.' => 'PO-001234',
            'Date' => '2026-09-15',
            'Net total' => '10,000.00',
            'VAT total' => '1,200.00',
            'Total sum' => '11,200.00',
            'Notes' => 'Delivery to Warehouse 1',
            'Code' => 'NES-BEAR-300G',
            'Name' => 'Bear Brand Powdered Milk 300g',
            'Amount' => '100',
            'Unit price' => '12.00',
        ],
        [
            'Supplier' => 'Nestle Philippines',
            'Type' => 'Purchase Order',
            'Status' => 'Confirmed',
            'P.O no.' => 'PO-001234',
            'Date' => '2026-09-15',
            'Net total' => '10,000.00',
            'VAT total' => '1,200.00',
            'Total sum' => '11,200.00',
            'Notes' => 'Delivery to Warehouse 1',
            'Code' => 'DISC-PROMO',
            'Name' => 'Special Volume Discount',
            'Amount' => '1',
            'Unit price' => '-500.00',
        ],
    ];

    $result = $parser->parse($rows);

    expect($result['orders'])->toHaveCount(1);

    $order = reset($result['orders']);
    expect($order['po_number'])->toBe('PO-001234')
        ->and($order['po_number_normalized'])->toBe('po001234')
        ->and($order['supplier'])->toBe('Nestle Philippines')
        ->and($order['status_normalized'])->toBe('confirmed')
        ->and($order['is_eligible'])->toBeTrue()
        ->and($order['total_amount'])->toBe('11200.00')
        ->and($order['items'])->toHaveCount(3);

    // Items
    expect($order['items'][0]['item_code'])->toBe('NES-MILO-1KG')
        ->and($order['items'][0]['quantity'])->toBe('50')
        ->and($order['items'][0]['is_financial_adjustment'])->toBeFalse();

    expect($order['items'][1]['item_code'])->toBe('NES-BEAR-300G')
        ->and($order['items'][1]['quantity'])->toBe('100')
        ->and($order['items'][1]['is_financial_adjustment'])->toBeFalse();

    // Financial adjustment
    expect($order['items'][2]['item_code'])->toBe('DISC-PROMO')
        ->and($order['items'][2]['is_financial_adjustment'])->toBeTrue();
});

test('PurchaseOrderSheetParser treats In Preparation as ineligible and Received as eligible', function () {
    /** @var PurchaseOrderSheetParser $parser */
    $parser = app(PurchaseOrderSheetParser::class);

    $rows = [
        [
            'Supplier' => 'Supplier A',
            'Status' => 'In preparation',
            'P.O no.' => 'PO-991',
            'Code' => 'SKU-1',
            'Name' => 'Draft Item',
            'Amount' => '10',
        ],
        [
            'Supplier' => 'Supplier B',
            'Status' => 'Received',
            'P.O no.' => 'PO-992',
            'Code' => 'SKU-2',
            'Name' => 'Completed Item',
            'Amount' => '20',
        ],
    ];

    $result = $parser->parse($rows);

    $po991 = $result['orders']['po991'];
    expect($po991['status_normalized'])->toBe('in_preparation')
        ->and($po991['is_eligible'])->toBeFalse();

    $po992 = $result['orders']['po992'];
    expect($po992['status_normalized'])->toBe('received')
        ->and($po992['is_eligible'])->toBeTrue();
});

test('PurchaseOrderSheetParser flags header mismatch errors across rows of same PO', function () {
    /** @var PurchaseOrderSheetParser $parser */
    $parser = app(PurchaseOrderSheetParser::class);

    $rows = [
        [
            'Supplier' => 'Supplier Original',
            'Status' => 'Confirmed',
            'P.O no.' => 'PO-DIFF',
            'Date' => '2026-09-01',
            'Code' => 'SKU-1',
            'Name' => 'Item 1',
            'Amount' => '5',
        ],
        [
            'Supplier' => 'Supplier Conflicting',
            'Status' => 'Confirmed',
            'P.O no.' => 'PO-DIFF',
            'Date' => '2026-09-02',
            'Code' => 'SKU-2',
            'Name' => 'Item 2',
            'Amount' => '10',
        ],
    ];

    $result = $parser->parse($rows);
    $order = $result['orders']['podiff'];

    expect($order['validation_errors'])->not()->toBeEmpty();
});
