<?php

use App\Enums\AiStatus;
use App\Enums\ReviewStatus;
use App\Models\AiExtraction;
use App\Models\ApiKey;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(function (): void {
    $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]);
});

it('rejects missing malformed expired revoked and wrong-ability keys', function (string $state): void {
    [$user, $header] = apiCredential($state);

    $request = $this->withHeader('Authorization', "Bearer {$header}");
    $request->getJson(route('api.v1.corrected-data.by-serial', ['serial_number' => 1]))->assertUnauthorized();
})->with(['malformed', 'expired', 'revoked', 'wrong ability']);

it('supports dedicated serial and po number lookup endpoints', function (): void {
    [, $header] = apiCredential('valid');
    $invoice = correctedExtraction(ReviewStatus::Verified, [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'PO Number', 'value' => 'PO-2026-0042'],
            ['label' => 'Total', 'value' => '125.00'],
        ],
        'items' => [],
    ]);
    $receipt = correctedExtraction(ReviewStatus::Verified, [
        'document_type' => 'Delivery Receipt',
        'fields' => [
            ['label' => 'PO Number', 'value' => 'PO-2026-0042'],
            ['label' => 'Receipt Number', 'value' => 'DR-42'],
        ],
        'items' => [
            ['description' => 'Paper cups delivered', 'quantity' => '3'],
        ],
    ], 'Delivery Receipt');
    $purchaseOrder = correctedExtraction(ReviewStatus::Verified, [
        'document_type' => 'Purchase Order',
        'fields' => [
            ['label' => 'PO Number', 'value' => 'PO-2026-0042'],
        ],
        'items' => [],
    ], 'Purchase Order');
    $rawInvoice = correctedExtraction(
        ReviewStatus::Pending,
        null,
        'Invoice',
        raw: [
            'document_type' => 'Invoice',
            'fields' => [
                ['label' => 'Invoice Number', 'value' => 'INV-RAW-42'],
                ['label' => 'PO Number', 'value' => 'po 2026 0042'],
                ['label' => 'Gross', 'value' => '750.00'],
            ],
            'items' => [
                ['description' => 'Raw AI item', 'quantity' => '2'],
            ],
        ],
    );

    $this->withHeader('Authorization', "Bearer {$header}")
        ->getJson(route('api.v1.corrected-data.by-serial', [
            'serial_number' => $invoice->receiving_upload_id,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $invoice->getKey());

    $response = $this->withHeader('Authorization', "Bearer {$header}")
        ->getJson(route('api.v1.corrected-data.by-po-number', [
            'po_number' => 'PO-2026-0042',
        ]));

    $response
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $invoice->getKey())
        ->assertJsonPath('data.0.verification_status', 'verified')
        ->assertJsonPath('data.1.id', $receipt->getKey())
        ->assertJsonPath('data.1.corrected_data.items.0.quantity', '3')
        ->assertJsonPath('data.2.id', $rawInvoice->getKey())
        ->assertJsonPath('data.2.corrected_data.fields.0.value', 'INV-RAW-42')
        ->assertJsonPath('data.2.corrected_data.items.0.description', 'Raw AI item')
        ->assertJsonPath('data.2.verification_status', 'unverified');

    expect(collect($response->json('data'))->pluck('id')->all())
        ->not->toContain($purchaseOrder->getKey());
});

it('returns every invoice and receipt file for a serial using raw ai data until it is verified', function (): void {
    [, $header] = apiCredential('valid');
    $upload = receivingUpload(ReviewStatus::Pending, 3);
    $invoice = correctedExtraction(
        ReviewStatus::Pending,
        null,
        'Invoice',
        $upload,
        [
            'document_type' => 'Invoice',
            'fields' => [
                ['label' => 'Invoice Number', 'value' => 'INV-RAW-100'],
                ['label' => 'PO Number', 'value' => 'PO-100'],
                ['label' => 'Gross', 'value' => '1,000.00'],
            ],
            'items' => [],
        ],
    );
    $receipt = correctedExtraction(
        ReviewStatus::Pending,
        null,
        'Delivery Receipt',
        $upload,
        [
            'document_type' => 'Delivery Receipt',
            'fields' => [
                ['label' => 'Invoice Number', 'value' => 'DR-RAW-101'],
                ['label' => 'PO Number', 'value' => 'PO-101'],
            ],
            'items' => [
                ['description' => 'Delivered cartons', 'quantity' => '4'],
            ],
        ],
    );
    $other = correctedExtraction(
        ReviewStatus::Pending,
        null,
        'Purchase Order',
        $upload,
        [
            'document_type' => 'Purchase Order',
            'fields' => [['label' => 'PO Number', 'value' => 'PO-100']],
            'items' => [],
        ],
    );

    $response = $this->withHeader('Authorization', "Bearer {$header}")
        ->getJson(route('api.v1.corrected-data.by-serial', [
            'serial_number' => "SN-{$upload->getKey()}",
        ]));

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $invoice->getKey())
        ->assertJsonPath('data.0.invoice_number', 'INV-RAW-100')
        ->assertJsonPath('data.0.corrected_data.fields.2.value', '1,000.00')
        ->assertJsonPath('data.0.verification_status', 'unverified')
        ->assertJsonPath('data.0.reviewed_at', null)
        ->assertJsonPath('data.1.id', $receipt->getKey())
        ->assertJsonPath('data.1.corrected_data.items.0.description', 'Delivered cartons')
        ->assertJsonPath('data.1.verification_status', 'unverified');

    expect(collect($response->json('data'))->pluck('id')->all())
        ->not->toContain($other->getKey());
});

it('validates required dedicated endpoint filters', function (string $routeName, string $field): void {
    [, $header] = apiCredential('valid');

    $this->withHeader('Authorization', "Bearer {$header}")
        ->getJson(route($routeName))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    ['api.v1.corrected-data.by-serial', 'serial_number'],
    ['api.v1.corrected-data.by-po-number', 'po_number'],
]);

it('stops authorizing a key when its owner loses upload-view permission', function (): void {
    [$user, $header] = apiCredential('valid');
    $user->syncRoles([]);

    $this->withHeader('Authorization', "Bearer {$header}")
        ->getJson(route('api.v1.corrected-data.by-serial', ['serial_number' => 1]))
        ->assertUnauthorized();
});

it('authorizes an explicitly never expiring key until it is revoked', function (): void {
    [, $header] = apiCredential('never');

    $this->withHeader('Authorization', "Bearer {$header}")
        ->getJson(route('api.v1.corrected-data.by-serial', ['serial_number' => 1]))
        ->assertOk();
});

/** @return array{User, string} */
function apiCredential(string $state): array
{
    $user = User::factory()->create();
    $user->assignRole('admin');
    $publicId = 'AbCdEfGhIjKlMnOp';
    $secret = str_repeat('a', 43);
    $header = "rcv_{$publicId}.{$secret}";

    if ($state === 'malformed') {
        return [$user, 'not-an-api-key'];
    }

    ApiKey::query()->create([
        'user_id' => $user->getKey(),
        'name' => 'Test integration',
        'public_id' => $publicId,
        'token_hash' => hash('sha256', $secret),
        'abilities' => $state === 'wrong ability' ? ['something-else'] : [ApiKey::ABILITY_READ_CORRECTED_DATA],
        'expires_at' => match ($state) {
            'expired' => now()->subMinute(),
            'never' => null,
            default => now()->addHour(),
        },
        'revoked_at' => $state === 'revoked' ? now() : null,
    ]);

    return [$user, $header];
}

/** @param array<string, mixed>|null $corrected @param array<string, mixed>|null $raw */
function correctedExtraction(
    ReviewStatus $status,
    ?array $corrected,
    string $documentType = 'Invoice',
    ?ReceivingUpload $upload = null,
    ?array $raw = null,
): AiExtraction {
    $upload ??= receivingUpload($status);
    $fileName = fake()->uuid().'.pdf';
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => $fileName,
        'sanitized_file_name' => $fileName,
        'stored_file_name' => $fileName,
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_staging_object_key' => 'staging/'.fake()->uuid().'.pdf',
        'original_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
        'review_status' => $status,
    ]);

    return AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => $documentType,
        'raw_extracted_json' => $raw ?? ['secret_raw' => true],
        'corrected_json' => $corrected,
        'ai_status' => AiStatus::Extracted,
        'review_status' => $status,
        'reviewed_at' => $status === ReviewStatus::Verified ? now() : null,
    ]);
}

function receivingUpload(ReviewStatus $status, int $fileCount = 1): ReceivingUpload
{
    $type = UploadType::query()->firstOrFail();
    $uploader = User::factory()->create();

    return ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'r2_bucket' => 'test',
        'file_count' => $fileCount,
        'review_status' => $status,
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'location_accuracy_meters' => 10,
        'location_captured_at' => now(),
        'upload_completed_at' => now(),
    ]);
}
