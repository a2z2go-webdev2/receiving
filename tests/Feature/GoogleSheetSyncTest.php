<?php

use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Models\AiExtraction;
use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetLog;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\PurchaseOrderItemFulfillment;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Services\GoogleSheets\GoogleSheetsDataSyncService;
use App\Services\GoogleSheets\GoogleSheetsTableParser;

beforeEach(function () {
    $this->artisan('db:seed', ['--force' => true]);
});

test('GoogleSheetsTableParser parses HTML export correctly', function () {
    $parser = app(GoogleSheetsTableParser::class);

    $html = '
        <table>
            <tr>
                <td>Serial Number</td>
                <td>Timestamp</td>
                <td>File Count</td>
                <td>Review Status</td>
                <td>Reviewed By</td>
            </tr>
            <tr>
                <td>1</td>
                <td>Aug 17, 2026 11:03:45 AM</td>
                <td>2</td>
                <td>Verified</td>
                <td>test@pingconmarketing.com</td>
            </tr>
            <tr>
                <td>2</td>
                <td>Aug 18, 2026 09:15:00 AM</td>
                <td>1</td>
                <td>Pending</td>
                <td>admin@pingconmarketing.com</td>
            </tr>
        </table>
    ';

    $parsed = $parser->parse($html);
    expect($parsed['logs'])->toHaveCount(2)
        ->and($parsed['logs'][0]['Serial Number'])->toBe('1')
        ->and($parsed['logs'][0]['Reviewed By'])->toBe('test@pingconmarketing.com');
});

test('GoogleSheetsDataSyncService stages and synchronizes serial number into database', function () {
    /** @var GoogleSheetsDataSyncService $syncService */
    $syncService = app(GoogleSheetsDataSyncService::class);

    $logs = [
        [
            'Serial Number' => '1',
            'Timestamp' => 'Aug 17, 2026 11:03:45 AM',
            'File Count' => '1',
            'Email Status' => 'Sent',
            'AI Status' => 'Extracted',
            'Review Status' => 'Verified',
            'Reviewed By' => 'jaezelle.benito@pingconmarketing.com',
            'Review Token' => 'sample_token_123',
            'Uploader Location' => '14.5995, 120.9842',
        ],
    ];

    $files = [
        [
            'serial_number' => 1,
            'file_name' => 'Invoice_SI12345.pdf',
            'file_id' => '1Glx9DnSnJW4gX768HkV1gWn1jcBxXzD1',
            'mime_type' => 'application/pdf',
            'r2_url' => 'https://pub-r2.receiving.com/receiving/bonita/2026/08/17/SN-1/Invoice_SI12345.pdf',
        ],
    ];

    $extractions = [
        [
            'serial_number' => 1,
            'ai_status' => 'Extracted',
            'corrected_json' => json_encode([
                'serialNumber' => 1,
                'documents' => [
                    [
                        'documentType' => 'Invoice',
                        'fileName' => 'Invoice_SI12345.pdf',
                        'fileId' => '1Glx9DnSnJW4gX768HkV1gWn1jcBxXzD1',
                        'fields' => [
                            ['label' => 'Invoice Number', 'value' => 'SI-12345'],
                            ['label' => 'PO Number', 'value' => 'PO-9988'],
                            ['label' => 'Supplier', 'value' => 'LENOTECH CORP'],
                            ['label' => 'Gross', 'value' => '50,000.00'],
                        ],
                        'items' => [
                            [
                                'description' => 'Lenovo ThinkPad Laptop X1',
                                'quantity' => '2',
                                'unitPrice' => '25,000.00',
                                'amount' => '50,000.00',
                            ],
                        ],
                    ],
                ],
            ]),
        ],
    ];

    // Stage data
    $syncService->stageData('bonita', $logs, $files, $extractions);

    $stagedLog = GoogleSheetLog::query()->where('sheet_slug', 'bonita')->where('serial_number', 1)->first();
    expect($stagedLog)->not->toBeNull()
        ->and($stagedLog->is_synced_to_db)->toBeFalse();

    // Execute Sync Serial 1
    $result = $syncService->syncSerialNumber('bonita', 1);

    expect($result['success'])->toBeTrue()
        ->and($result['upload_id'])->toBeGreaterThan(0);

    // Verify ReceivingUpload created in DB
    $upload = ReceivingUpload::query()->find($result['upload_id']);
    expect($upload)->not->toBeNull()
        ->and($upload->latitude)->toBe(14.5995)
        ->and($upload->longitude)->toBe(120.9842)
        ->and($upload->files)->toHaveCount(1)
        ->and($upload->extractions)->toHaveCount(1);

    // Verify Staged Log marked as synced
    $stagedLog->refresh();
    expect($stagedLog->is_synced_to_db)->toBeTrue()
        ->and($stagedLog->synced_receiving_upload_id)->toBe($upload->getKey());
});

test('GoogleSheetsDataSyncService calculates batch preview and handles exclusions', function () {
    /** @var GoogleSheetsDataSyncService $syncService */
    $syncService = app(GoogleSheetsDataSyncService::class);

    $logs = [];
    for ($i = 1; $i <= 10; $i++) {
        $logs[] = [
            'Serial Number' => (string) $i,
            'Timestamp' => 'Aug 17, 2026 11:00:00 AM',
            'File Count' => '1',
            'Review Status' => 'Pending',
        ];
    }

    $syncService->stageData('keysys', $logs, [], []);

    $preview = $syncService->calculateBatchPreview(
        'keysys',
        limit: 5,
        includeSerials: '1-10',
        excludeSerials: '3, 7',
        sortOrder: 'ASC'
    );

    expect($preview['totalPendingCount'])->toBe(10)
        ->and($preview['matchedCount'])->toBe(5)
        ->and($preview['sampleSerials'])->not->toContain(3)
        ->and($preview['sampleSerials'])->not->toContain(7);
});

test('Admin can access sheets sync index and items API', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.sheets-sync.index'))
        ->assertOk();

    $this->actingAs($user)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->getJson(route('admin.sheets-sync.items', ['sheet' => 'bonita']))
        ->assertOk()
        ->assertJsonStructure(['items', 'pagination']);
});

test('Extracted data automatically matches with PO item records for consistency', function () {
    /** @var GoogleSheetsDataSyncService $syncService */
    $syncService = app(GoogleSheetsDataSyncService::class);
    $normalizer = app(PurchaseOrderDataNormalizer::class);

    // Create a master PO Item Schedule Record
    $schedule = PurchaseOrderItemSchedule::query()->create([
        'sku_number' => 'SKU-LENOVO-X1',
        'sku_number_normalized' => $normalizer->normalizeIdentifier('SKU-LENOVO-X1'),
        'description' => 'Lenovo ThinkPad Laptop X1 Carbon',
        'description_normalized' => $normalizer->normalizeDescription('Lenovo ThinkPad Laptop X1 Carbon'),
        'ean_barcode' => '8806091234567',
        'ean_barcode_normalized' => '8806091234567',
        'target_quantity' => 10.0,
        'unit' => 'unit',
        'unit_price' => '25000.00',
        'is_active' => true,
    ]);

    $logs = [
        [
            'Serial Number' => '55',
            'Timestamp' => 'Aug 17, 2026 11:03:45 AM',
            'File Count' => '1',
            'Email Status' => 'Sent',
            'AI Status' => 'Extracted',
            'Review Status' => 'Verified',
            'Reviewed By' => 'test@pingconmarketing.com',
            'Uploader Location' => '14.5995, 120.9842',
        ],
    ];

    $files = [
        [
            'serial_number' => 55,
            'file_name' => 'PO_9988_Lenovo.pdf',
            'file_id' => 'file_55_lenovo',
            'mime_type' => 'application/pdf',
            'r2_url' => 'https://pub-r2.receiving.com/receiving/bonita/2026/08/17/SN-55/PO_9988_Lenovo.pdf',
        ],
    ];

    $extractions = [
        [
            'serial_number' => 55,
            'ai_status' => 'Extracted',
            'corrected_json' => json_encode([
                'serialNumber' => 55,
                'documents' => [
                    [
                        'documentType' => 'Purchase Order',
                        'fileName' => 'PO_9988_Lenovo.pdf',
                        'fields' => [
                            ['label' => 'PO Number', 'value' => 'PO-9988'],
                            ['label' => 'PO Date', 'value' => '2026-08-17'],
                            ['label' => 'Supplier', 'value' => 'Lenovo Direct PH'],
                            ['label' => 'Gross', 'value' => '50,000.00'],
                        ],
                        'items' => [
                            [
                                'itemCode' => 'SKU-LENOVO-X1',
                                'description' => 'Lenovo ThinkPad Laptop X1 Carbon 14in',
                                'quantity' => '2',
                                'unitPrice' => '25,000.00',
                                'amount' => '50,000.00',
                            ],
                        ],
                    ],
                ],
            ]),
        ],
    ];

    $syncService->stageData('bonita', $logs, $files, $extractions);
    $result = $syncService->syncSerialNumber('bonita', 55);

    expect($result['success'])->toBeTrue();

    // Verify PO Extraction Item was created
    $poExt = PoExtraction::query()->where('po_number', 'PO-9988')->first();
    expect($poExt)->not->toBeNull()
        ->and($poExt->items)->toHaveCount(1);

    // Verify PurchaseOrderItemFulfillment was automatically matched to the master PO Schedule Item!
    $fulfillment = PurchaseOrderItemFulfillment::query()
        ->where('purchase_order_item_schedule_id', $schedule->getKey())
        ->first();

    expect($fulfillment)->not->toBeNull()
        ->and((float) $fulfillment->ordered_quantity)->toBe(2.0)
        ->and($fulfillment->matched_by)->toBe('sku');
});

test('Google Sheets Webhook endpoint rejects unauthorized requests', function () {
    $config = GoogleSheetConfig::query()->where('slug', 'bonita')->first();
    $config->update(['webhook_secret' => 'whsec_test_secret_123']);

    // Call without secret
    $this->postJson(route('api.webhooks.sheets', ['slug' => 'bonita']), [
        'serial_number' => 88,
    ])->assertStatus(401);

    // Call with invalid secret
    $this->withHeader('X-Webhook-Secret', 'invalid_secret')
        ->postJson(route('api.webhooks.sheets', ['slug' => 'bonita']), [
            'serial_number' => 88,
        ])->assertStatus(401);
});

test('Google Sheets Webhook endpoint receives payload and automatically syncs to database', function () {
    $config = GoogleSheetConfig::query()->where('slug', 'bonita')->first();
    $config->update([
        'webhook_secret' => 'whsec_test_secret_123',
        'auto_sync_on_webhook' => true,
    ]);

    $payload = [
        'serial_number' => 77,
        'log' => [
            'Serial Number' => '77',
            'Timestamp' => 'Aug 17, 2026 11:03:45 AM',
            'File Count' => '1',
            'Email Status' => 'Sent',
            'AI Status' => 'Extracted',
            'Review Status' => 'Verified',
            'Reviewed By' => 'webhook_reviewer@pingconmarketing.com',
            'Uploader Location' => '14.5995, 120.9842',
        ],
        'files' => [
            [
                'serial_number' => 77,
                'file_name' => 'Invoice_77.pdf',
                'file_id' => 'file_77_test',
                'mime_type' => 'application/pdf',
                'r2_url' => 'https://pub-r2.receiving.com/receiving/bonita/2026/08/17/SN-77/Invoice_77.pdf',
            ],
        ],
        'extraction' => [
            'serial_number' => 77,
            'ai_status' => 'Extracted',
            'corrected_json' => json_encode([
                'serialNumber' => 77,
                'documents' => [
                    [
                        'documentType' => 'Invoice',
                        'fileName' => 'Invoice_77.pdf',
                        'fields' => [
                            ['label' => 'Invoice Number', 'value' => 'SI-7777'],
                            ['label' => 'Supplier', 'value' => 'WEBHOOK SUPPLIER INC'],
                        ],
                    ],
                ],
            ]),
        ],
    ];

    $response = $this->withHeader('X-Webhook-Secret', 'whsec_test_secret_123')
        ->postJson(route('api.webhooks.sheets', ['slug' => 'bonita']), $payload);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'sheet' => 'bonita',
            'serial_number' => 77,
            'auto_synced' => true,
        ]);

    // Verify ReceivingUpload exists in DB
    $upload = ReceivingUpload::query()->find($response->json('upload_id'));
    expect($upload)->not->toBeNull()
        ->and($upload->files)->toHaveCount(1);

    // Verify Staging Log is marked as synced
    $stagedLog = GoogleSheetLog::query()->where('sheet_slug', 'bonita')->where('serial_number', 77)->first();
    expect($stagedLog)->not->toBeNull()
        ->and($stagedLog->is_synced_to_db)->toBeTrue();

    // Verify NO synthetic PoExtraction was created for Invoice
    $fakePo = PoExtraction::query()->where('po_number', 'LIKE', 'PO-SN%')->first();
    expect($fakePo)->toBeNull();
});

test('Syncing invoice with existing PO number links to real PO without creating fake PO extractions', function () {
    /** @var GoogleSheetsDataSyncService $syncService */
    $syncService = app(GoogleSheetsDataSyncService::class);

    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'bonita')->first() ?? UploadType::factory()->create(['slug' => 'bonita']);

    // 1. Create a real PO in the system
    $realPoUpload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $user->getKey(),
        'uploader_email' => $user->email,
        'r2_bucket' => 'test-bucket',
        'r2_prefix' => 'receiving/bonita',
        'file_count' => 1,
    ]);
    $realPoFile = UploadedFile::query()->create([
        'receiving_upload_id' => $realPoUpload->getKey(),
        'original_file_name' => 'PO_4455.pdf',
        'sanitized_file_name' => 'PO_4455.pdf',
        'stored_file_name' => 'PO_4455.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test-bucket',
        'r2_object_key' => 'receiving/PO_4455.pdf',
        'r2_staging_object_key' => "staging/{$realPoUpload->getKey()}/PO_4455.pdf",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
    ]);
    $realPoAiExt = AiExtraction::query()->create([
        'receiving_upload_id' => $realPoUpload->getKey(),
        'uploaded_file_id' => $realPoFile->getKey(),
        'document_type' => 'purchase order',
        'raw_extracted_json' => [
            'documentType' => 'Purchase Order',
            'fields' => [
                ['label' => 'PO Number', 'value' => 'PO-4455'],
            ],
        ],
        'corrected_json' => [
            'documentType' => 'Purchase Order',
            'fields' => [
                ['label' => 'PO Number', 'value' => 'PO-4455'],
            ],
        ],
        'ai_status' => 'extracted',
        'review_status' => 'verified',
    ]);
    $realPoExt = PoExtraction::query()->create([
        'ai_extraction_id' => $realPoAiExt->getKey(),
        'receiving_upload_id' => $realPoUpload->getKey(),
        'po_number' => 'PO-4455',
        'po_number_normalized' => 'po4455',
        'arrival_status' => 'pending',
    ]);

    // 2. Sync an invoice referencing PO-4455
    $logs = [
        [
            'serial_number' => 88,
            'branch' => 'Main',
            'uploader_email' => 'uploader@example.com',
            'status' => 'synced',
            'created_at' => '2026-08-18 10:00:00',
        ],
    ];

    $files = [
        [
            'serial_number' => 88,
            'file_name' => 'Invoice_88.pdf',
            'file_id' => 'file_88_inv',
            'mime_type' => 'application/pdf',
            'r2_url' => 'https://pub-r2.receiving.com/receiving/bonita/2026/08/18/SN-88/Invoice_88.pdf',
        ],
    ];

    $extractions = [
        [
            'serial_number' => 88,
            'ai_status' => 'Extracted',
            'corrected_json' => json_encode([
                'serialNumber' => 88,
                'documents' => [
                    [
                        'documentType' => 'Invoice',
                        'fileName' => 'Invoice_88.pdf',
                        'fields' => [
                            ['label' => 'Invoice Number', 'value' => 'INV-8888'],
                            ['label' => 'PO Number', 'value' => 'PO-4455'],
                            ['label' => 'Supplier', 'value' => 'TEST SUPPLIER PH'],
                            ['label' => 'Description', 'value' => 'Sample Product Item'],
                            ['label' => 'Quantity', 'value' => '10'],
                        ],
                    ],
                ],
            ]),
        ],
    ];

    $syncService->stageData('bonita', $logs, $files, $extractions);
    $result = $syncService->syncSerialNumber('bonita', 88);

    expect($result['success'])->toBeTrue();

    // Verify invoice is linked to the real PO
    $link = PurchaseOrderDocumentLink::query()->where('po_extraction_id', $realPoExt->getKey())->first();
    expect($link)->not->toBeNull();

    // Verify NO fake PO was created
    $fakePo = PoExtraction::query()->where('po_number', 'PO-SN88')->first();
    expect($fakePo)->toBeNull();
});
