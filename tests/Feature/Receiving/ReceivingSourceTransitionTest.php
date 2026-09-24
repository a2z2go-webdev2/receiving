<?php

use App\Enums\ReceivingSourceMode;
use App\Enums\ReviewStatus;
use App\Models\AiExtraction;
use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetExtraction;
use App\Models\GoogleSheetFile;
use App\Models\GoogleSheetLog;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use App\Services\GoogleSheets\GoogleSheetsDataSyncService;

beforeEach(function () {
    $this->artisan('db:seed', ['--force' => true]);
});

test('parallel mode allows new sheet serial imports to create uploads', function () {
    /** @var GoogleSheetsDataSyncService $syncService */
    $syncService = app(GoogleSheetsDataSyncService::class);

    $config = GoogleSheetConfig::query()->where('slug', 'a2z2go')->firstOrFail();
    $config->update(['transition_mode' => ReceivingSourceMode::Parallel->value]);

    GoogleSheetLog::query()->create([
        'sheet_slug' => 'a2z2go',
        'serial_number' => 8881,
        'file_count' => 1,
        'review_status' => 'Pending',
        'reviewed_by' => 'reviewer@example.com',
        'timestamp' => now()->toDateTimeString(),
    ]);

    GoogleSheetFile::query()->create([
        'sheet_slug' => 'a2z2go',
        'serial_number' => 8881,
        'file_id' => 'file_8881',
        'file_name' => 'receipt_8881.jpg',
        'r2_url' => 'https://r2.example.com/receipt_8881.jpg',
    ]);

    $result = $syncService->syncSerialNumber('a2z2go', 8881);

    expect($result['success'])->toBeTrue()
        ->and($result['upload_id'])->not->toBeNull();

    $upload = ReceivingUpload::query()->find($result['upload_id']);
    expect($upload)->not->toBeNull()
        ->and($upload->serial_number)->toBe(8881);
});

test('app_only mode rejects new receiving serial imports from sheets and marks them staged', function () {
    /** @var GoogleSheetsDataSyncService $syncService */
    $syncService = app(GoogleSheetsDataSyncService::class);

    $config = GoogleSheetConfig::query()->where('slug', 'a2z2go')->firstOrFail();
    $config->update(['transition_mode' => ReceivingSourceMode::AppOnly->value]);

    GoogleSheetLog::query()->create([
        'sheet_slug' => 'a2z2go',
        'serial_number' => 8882,
        'file_count' => 1,
        'review_status' => 'Pending',
        'reviewed_by' => 'reviewer@example.com',
        'timestamp' => now()->toDateTimeString(),
    ]);

    GoogleSheetFile::query()->create([
        'sheet_slug' => 'a2z2go',
        'serial_number' => 8882,
        'file_id' => 'file_8882',
        'file_name' => 'receipt_8882.jpg',
        'r2_url' => 'https://r2.example.com/receipt_8882.jpg',
    ]);

    $result = $syncService->syncSerialNumber('a2z2go', 8882);

    expect($result['success'])->toBeFalse()
        ->and($result['upload_id'])->toBeNull()
        ->and($result['message'])->toContain('app-only transition mode');

    $uploadType = UploadType::query()->where('slug', 'a2z2go')->first();
    $upload = ReceivingUpload::query()
        ->where('upload_type_id', $uploadType?->id)
        ->where('serial_number', 8882)
        ->first();

    expect($upload)->toBeNull();
});

test('reconciliation mode preserves app-verified corrections and does not overwrite them from sheet updates', function () {
    /** @var GoogleSheetsDataSyncService $syncService */
    $syncService = app(GoogleSheetsDataSyncService::class);

    $config = GoogleSheetConfig::query()->where('slug', 'a2z2go')->firstOrFail();
    $config->update(['transition_mode' => ReceivingSourceMode::Reconciliation->value]);

    $uploadType = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();

    $user = User::factory()->create();

    $upload = ReceivingUpload::query()->create([
        'submission_id' => (string) Str::uuid(),
        'upload_type_id' => $uploadType->getKey(),
        'serial_number' => 8883,
        'uploader_user_id' => $user->getKey(),
        'uploader_email' => 'operator@pingconmarketing.com',
        'r2_bucket' => 'receiving-production',
        'r2_prefix' => 'a2z2go',
        'file_count' => 1,
        'review_status' => ReviewStatus::Verified,
    ]);

    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'receipt_8883.jpg',
        'sanitized_file_name' => 'receipt_8883.jpg',
        'stored_file_name' => 'fid_receipt_8883.jpg',
        'file_extension' => 'jpg',
        'r2_bucket' => 'receiving-production',
        'r2_object_key' => 'receipt_8883.jpg',
        'r2_staging_object_key' => 'staging/receipt_8883.jpg',
        'original_file_size' => 1024,
        'final_file_size' => 1024,
        'declared_content_type' => 'image/jpeg',
        'content_type' => 'image/jpeg',
    ]);

    $verifiedJson = [
        'documentType' => 'Invoice',
        'fields' => [
            ['label' => 'Invoice Number', 'value' => 'INV-VERIFIED-HUMAN'],
        ],
    ];

    AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => 'invoice',
        'invoice_number' => 'INV-VERIFIED-HUMAN',
        'raw_extracted_json' => ['documentType' => 'Invoice', 'fields' => []],
        'corrected_json' => $verifiedJson,
        'review_status' => ReviewStatus::Verified->value,
    ]);

    // Now incoming sheet data has a different unverified value
    GoogleSheetLog::query()->create([
        'sheet_slug' => 'a2z2go',
        'serial_number' => 8883,
        'file_count' => 1,
        'review_status' => 'Pending',
        'timestamp' => now()->toDateTimeString(),
        'synced_receiving_upload_id' => $upload->getKey(),
    ]);

    GoogleSheetFile::query()->create([
        'sheet_slug' => 'a2z2go',
        'serial_number' => 8883,
        'file_id' => 'fid',
        'file_name' => 'receipt_8883.jpg',
    ]);

    GoogleSheetExtraction::query()->create([
        'sheet_slug' => 'a2z2go',
        'serial_number' => 8883,
        'raw_ai_json' => json_encode([
            'documents' => [
                [
                    'fileId' => 'fid',
                    'documentType' => 'Invoice',
                    'fields' => [
                        ['label' => 'Invoice Number', 'value' => 'INV-OLD-SHEET'],
                    ],
                ],
            ],
        ]),
    ]);

    $result = $syncService->syncSerialNumber('a2z2go', 8883);

    expect($result['success'])->toBeTrue()
        ->and($result['upload_id'])->toBe($upload->getKey());

    $extraction = AiExtraction::query()
        ->where('receiving_upload_id', $upload->getKey())
        ->where('uploaded_file_id', $file->getKey())
        ->firstOrFail();

    // Human corrected JSON and Verified review status must remain preserved!
    expect($extraction->review_status)->toBe(ReviewStatus::Verified)
        ->and($extraction->corrected_json)->toBe($verifiedJson);
});
