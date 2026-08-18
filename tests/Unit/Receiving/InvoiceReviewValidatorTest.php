<?php

use App\Features\Receiving\Services\InvoiceReviewValidator;

it('converts legacy extraction data to editable fields without obsolete accounting inputs', function (): void {
    $result = app(InvoiceReviewValidator::class)->normalize([
        'document_type' => 'invoice',
        'supplier_name' => 'ABC Supplier',
        'buyer_address' => '73 Kaingin Road',
        'account_title' => 'Purchases',
        'ewt_1_percent' => '100.00',
        'ewt_2_percent' => null,
        'atc' => '158',
        'items' => [['description' => 'Service', 'amount' => 100]],
    ], false);

    expect($result)->toMatchArray([
        'document_type' => 'invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'ABC Supplier'],
            ['label' => 'Buyer Address', 'value' => '73 Kaingin Road'],
        ],
        'items' => [['description' => 'Service', 'amount' => '100']],
    ])->and(collect($result['fields'])->pluck('label')->all())
        ->not->toContain('Account Title', 'EWT 1', 'EWT 2', 'ATC');
});

it('preserves editable non invoice fields and removes obsolete labels from structured data', function (): void {
    $result = app(InvoiceReviewValidator::class)->normalize([
        'document_type' => 'Delivery Receipt',
        'fields' => [
            ['label' => 'Received By', 'value' => 'Jane'],
            ['label' => 'ATC', 'value' => '158'],
            ['label' => 'EWT 1%', 'value' => '50'],
        ],
        'items' => [],
    ], true);

    expect($result)->toBe([
        'document_type' => 'Delivery Receipt',
        'fields' => [['label' => 'Received By', 'value' => 'Jane']],
        'items' => [],
    ]);
});
