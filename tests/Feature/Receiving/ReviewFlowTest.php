<?php

use App\Enums\AiStatus;
use App\Enums\ReviewStatus;
use App\Models\ActivityLog;
use App\Models\AiExtraction;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed(UploadTypeSeeder::class));

it('shows structured editable data without exposing raw json', function (): void {
    [$upload, $extraction, $token] = structuredReviewFixture();
    $extraction->forceFill(['corrected_json' => null])->save();

    $this->get(route('receiving.review.show', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('review/show')
            ->where('upload.files.0.extraction.corrected_data.document_type', 'Invoice')
            ->where('upload.files.0.extraction.corrected_data.fields.0.label', 'Company Name')
            ->missing('upload.files.0.extraction.raw_data'));
});

it('saves editable document type fields and items without obsolete accounting fields', function (): void {
    [$upload, $extraction, $token] = structuredReviewFixture();

    $this->put(route('receiving.review.update', ['token' => $token, 'extraction' => $extraction]), [
        'corrected_data' => [
            'document_type' => 'Delivery Receipt',
            'fields' => [
                ['label' => 'Received By', 'value' => 'Jane Doe'],
                ['label' => 'Account Title', 'value' => 'Must be removed'],
                ['label' => 'Custom Reference', 'value' => 'REF-100'],
            ],
            'items' => [
                ['description' => 'Box', 'quantity' => '2', 'unitPrice' => '', 'amount' => ''],
            ],
        ],
    ])->assertRedirect();

    $corrected = $extraction->refresh()->corrected_json;
    expect($extraction->document_type)->toBe('Delivery Receipt')
        ->and($corrected['fields'])->toBe([
            ['label' => 'Received By', 'value' => 'Jane Doe'],
            ['label' => 'Custom Reference', 'value' => 'REF-100'],
        ])
        ->and($corrected['items'])->toHaveCount(1)
        ->and($upload->refresh()->review_status)->toBe(ReviewStatus::Revision);
});

it('atomically saves every visible draft and consumes all transaction links', function (): void {
    [$upload, $extraction, $token, $link] = structuredReviewFixture();
    $extraction->forceFill(['corrected_json' => null])->save();
    $reviewed = [
        'document_type' => 'Purchase Order',
        'fields' => [
            ['label' => 'PO Number', 'value' => 'PO-900'],
            ['label' => 'Approved By', 'value' => 'Reviewer'],
        ],
        'items' => [],
    ];

    $this->post(route('receiving.review.verify', ['token' => $token]), [
        'corrected_data' => [$extraction->getKey() => $reviewed],
    ])->assertRedirect(route('receiving.review.completed'));

    expect($upload->refresh()->review_status)->toBe(ReviewStatus::Verified)
        ->and($extraction->refresh()->review_status)->toBe(ReviewStatus::Verified)
        ->and($extraction->document_type)->toBe('Purchase Order')
        ->and($extraction->corrected_json['fields'][1]['value'])->toBe('Reviewer')
        ->and(ReviewLink::query()->where('receiving_upload_id', $upload->getKey())->whereNull('used_at')->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('action', 'scanned_data_verified')->value('message'))
        ->toContain($link->email)
        ->toContain('ready for reporting');
});

it('rejects nested field values instead of accepting ambiguous json', function (): void {
    [$upload, $extraction, $token] = structuredReviewFixture();

    $this->post(route('receiving.review.verify', ['token' => $token]), [
        'corrected_data' => [$extraction->getKey() => [
            'document_type' => 'Invoice',
            'fields' => [['label' => 'Company Name', 'value' => ['nested' => 'payload']]],
            'items' => [],
        ]],
    ])->assertSessionHasErrors("corrected_data.{$extraction->getKey()}.fields.0.value");

    expect($upload->refresh()->review_status)->toBe(ReviewStatus::Pending);
});

it('refuses verification while any accepted file extraction failed', function (): void {
    [$upload, $extraction, $token] = structuredReviewFixture();
    $extraction->forceFill(['ai_status' => AiStatus::Failed, 'corrected_json' => null])->save();

    $this->post(route('receiving.review.verify', ['token' => $token]))->assertUnprocessable();
    expect($upload->refresh()->review_status)->toBe(ReviewStatus::Pending);
});

it('rejects final review data for extraction ids outside the linked upload', function (): void {
    [$upload, $extraction, $token] = structuredReviewFixture();

    $this->post(route('receiving.review.verify', ['token' => $token]), [
        'corrected_data' => [
            $extraction->getKey() => $extraction->corrected_json,
            999_999 => $extraction->corrected_json,
        ],
    ])->assertSessionHasErrors('corrected_data');

    expect($upload->refresh()->review_status)->toBe(ReviewStatus::Pending);
});

it('rejects expired and already consumed review bearer tokens', function (string $state): void {
    [$upload, $extraction, $token, $link] = structuredReviewFixture();
    $link->forceFill($state === 'expired' ? ['expires_at' => now()->subSecond()] : ['used_at' => now()])->save();

    $this->get(route('receiving.review.show', ['token' => $token]))->assertGone();
})->with(['expired', 'used']);

/** @return array{ReceivingUpload, AiExtraction, string, ReviewLink} */
function structuredReviewFixture(): array
{
    $type = UploadType::query()->firstOrFail();
    $user = User::factory()->create();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(), 'uploader_user_id' => $user->getKey(), 'uploader_email' => $user->email,
        'r2_bucket' => 'test', 'r2_prefix' => 'receiving/test', 'file_count' => 1,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'original_file_name' => 'invoice.pdf', 'sanitized_file_name' => 'invoice.pdf',
        'stored_file_name' => 'invoice.pdf', 'file_extension' => 'pdf', 'r2_bucket' => 'test', 'r2_object_key' => 'receiving/invoice.pdf',
        'r2_staging_object_key' => 'staging/invoice.pdf', 'original_file_size' => 100, 'final_file_size' => 100,
        'declared_content_type' => 'application/pdf', 'content_type' => 'application/pdf', 'ai_status' => AiStatus::Extracted,
    ]);
    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'ABC Supplier'],
            ['label' => 'Gross', 'value' => '112.00'],
        ],
        'items' => [],
    ];
    $extraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice', 'raw_extracted_json' => $data, 'corrected_json' => $data,
        'ai_status' => AiStatus::Extracted,
    ]);
    $token = str_repeat('a', 64);
    $link = ReviewLink::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'email' => $user->email, 'upload_type_id' => $type->getKey(),
        'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(),
    ]);

    return [$upload, $extraction, $token, $link];
}
