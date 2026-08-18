<?php

use App\Features\Receiving\Services\CorrectedDataMetadata;

it('extracts an invoice number from normalized corrected fields', function (string $label): void {
    expect(CorrectedDataMetadata::invoiceNumber([
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Example Supplier'],
            ['label' => $label, 'value' => ' INV-2026-0042 '],
        ],
    ]))->toBe('INV-2026-0042');
})->with(['Invoice Number', 'invoice no', 'INVOICE NUM', 'Invoice #']);

it('does not create an ambiguous lookup key from invalid corrected data', function (mixed $data): void {
    expect(CorrectedDataMetadata::invoiceNumber($data))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [[]],
    'invalid fields' => [['fields' => 'not-an-array']],
    'different label' => [['fields' => [['label' => 'Invoice Date', 'value' => '2026-07-02']]]],
    'empty invoice' => [['fields' => [['label' => 'Invoice Number', 'value' => '']]]],
    'overlong invoice' => [['fields' => [['label' => 'Invoice Number', 'value' => str_repeat('A', 101)]]]],
]);
