<?php

use App\Features\Receiving\Services\CorrectedDataMetadata;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;

it('extracts an invoice number from normalized corrected fields', function (string $label): void {
    expect(CorrectedDataMetadata::invoiceNumber([
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Example Supplier'],
            ['label' => $label, 'value' => ' INV-2026-0042 '],
        ],
    ]))->toBe('INV-2026-0042');
})->with(['Invoice Number', 'invoice no', 'INVOICE NUM', 'Invoice #']);

it('does not create an ambiguous invoice key from invalid corrected data', function (mixed $data): void {
    expect(CorrectedDataMetadata::invoiceNumber($data))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [[]],
    'invalid fields' => [['fields' => 'not-an-array']],
    'different label' => [['fields' => [['label' => 'Invoice Date', 'value' => '2026-07-02']]]],
    'empty invoice' => [['fields' => [['label' => 'Invoice Number', 'value' => '']]]],
    'see image' => [['fields' => [['label' => 'Invoice Number', 'value' => '[see image]']]]],
    'placeholder hashes' => [['fields' => [['label' => 'Invoice Number', 'value' => '###']]]],
    'overlong invoice' => [['fields' => [['label' => 'Invoice Number', 'value' => str_repeat('A', 101)]]]],
]);

it('extracts po number from top-level keys first', function (string $key): void {
    expect(CorrectedDataMetadata::poNumber([
        $key => 'PO-716',
        'fields' => [
            ['label' => 'PO Number', 'value' => 'OTHER-999'],
        ],
    ]))->toBe('PO-716');
})->with(['po_number', 'poNumber', 'po']);

it('falls back to fields array when top-level key is missing or not meaningful', function (mixed $topLevelValue): void {
    expect(CorrectedDataMetadata::poNumber([
        'po_number' => $topLevelValue,
        'fields' => [
            ['label' => 'PO Number', 'value' => '716'],
        ],
    ]))->toBe('716');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
    'see image' => ['[see image]'],
    'hashes' => ['###'],
]);

it('extracts po number across all supported field labels', function (string $label): void {
    expect(CorrectedDataMetadata::poNumber([
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Symmetryplast Enterprises'],
            ['label' => $label, 'value' => ' 716 '],
        ],
    ]))->toBe('716');
})->with([
    'po number',
    'PO Number',
    'purchase order number',
    'Purchase Order Number',
    'purchase order no',
    'po no',
    'PO No',
    'p.o. number',
    'P.O. Number',
    'p.o. no',
    'P.O. No',
    'po #',
    'PO #',
    'p.o. #',
    'P.O. #',
    'purchase order',
    'Purchase Order',
    'purchase order #',
    'Purchase Order #',
    'purchase order ref',
    'Purchase Order Ref',
    'order number',
    'Order Number',
    'order no',
    'Order No',
    'po',
    'PO',
    'p.o.',
    'P.O.',
    'po ref',
    'PO Ref',
    'po reference',
    'PO Reference',
    'customer po',
    'Customer PO',
    'customer po number',
    'Customer PO Number',
    'buyer po',
    'Buyer PO',
    'ref po',
    'Ref PO',
    'ref po no',
    'Ref PO No',
    'po num',
    'PO Num',
    'p.o. num',
    'P.O. Num',
]);

it('returns null for non-meaningful or invalid po number values', function (mixed $data): void {
    expect(CorrectedDataMetadata::poNumber($data))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [[]],
    'invalid fields' => [['fields' => 'not-an-array']],
    'different label' => [['fields' => [['label' => 'Invoice Number', 'value' => '6634']]]],
    'empty value' => [['fields' => [['label' => 'PO Number', 'value' => '']]]],
    'see image' => [['fields' => [['label' => 'PO Number', 'value' => '[see image]']]]],
    'hashes' => [['fields' => [['label' => 'PO Number', 'value' => '###']]]],
    'overlong' => [['fields' => [['label' => 'PO Number', 'value' => str_repeat('X', 151)]]]],
]);

it('maintains parity with PurchaseOrderDataNormalizer for po number extraction', function (array $data): void {
    $normalizer = new PurchaseOrderDataNormalizer;
    $fromMetadata = CorrectedDataMetadata::poNumber($data);
    $fromNormalizer = $normalizer->poNumber($data);

    expect($fromMetadata)->toBe($fromNormalizer);
})->with([
    'top-level po_number' => [['po_number' => '716']],
    'top-level poNumber' => [['poNumber' => 'PO-716']],
    'top-level po' => [['po' => '716']],
    'field PO Number' => [['fields' => [['label' => 'PO Number', 'value' => '716']]]],
    'field Purchase Order Ref' => [['fields' => [['label' => 'Purchase Order Ref', 'value' => 'PO-716']]]],
    'field Buyer PO' => [['fields' => [['label' => 'Buyer PO', 'value' => '716']]]],
    'field Customer PO Number' => [['fields' => [['label' => 'Customer PO Number', 'value' => '716']]]],
    'field PO Num' => [['fields' => [['label' => 'PO Num', 'value' => '716']]]],
    'placeholder hashes in top-level' => [['po_number' => '###', 'fields' => [['label' => 'PO Number', 'value' => '716']]]],
    'placeholder hashes in fields' => [['fields' => [['label' => 'PO Number', 'value' => '###']]]],
]);

it('normalizes identifiers correctly', function (?string $input, ?string $expected): void {
    expect(CorrectedDataMetadata::normalizedIdentifier($input))->toBe($expected);
})->with([
    'plain number' => ['716', '716'],
    'prefixed PO' => ['PO-716', 'po716'],
    'hash prefixed' => ['PO# 716', 'po716'],
    'with leading zeros' => ['00716', '00716'],
    'whitespace' => ['  PO 716  ', 'po716'],
    'null' => [null, null],
    'empty' => ['', null],
    'see image' => ['[see image]', null],
    'hashes' => ['###', null],
    'only symbols' => ['---', null],
]);

it('extracts po date from recognized labels', function (string $label): void {
    expect(CorrectedDataMetadata::poDate([
        'fields' => [
            ['label' => $label, 'value' => ' 2026-09-21 '],
        ],
    ]))->toBe('2026-09-21');
})->with(['PO Date', 'purchase order date', 'P.O. Date']);
