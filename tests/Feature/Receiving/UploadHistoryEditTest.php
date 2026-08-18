<?php

use App\Enums\AiStatus;
use App\Enums\ReviewStatus;
use App\Models\ActivityLog;
use App\Models\AiExtraction;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed(UploadTypeSeeder::class));

it('shows edit corrected data link on history for verified uploads', function (): void {
    [$uploader, $type, $upload] = verifiedUploadFixture();

    $this->actingAs($uploader)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->get(route('receiving.upload.history', ['uploadType' => $type]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('upload/history')
            ->where('uploads.data.0.id', $upload->getKey())
            ->where('uploads.data.0.review_status', 'verified'));
});

it('allows uploader with active session to view edit page for verified upload', function (): void {
    [$uploader, $type, $upload, $extraction] = verifiedUploadFixture();

    $this->actingAs($uploader)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->get(route('receiving.upload.history.edit', ['uploadType' => $type, 'upload' => $upload]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('upload/edit-verified')
            ->where('upload.id', $upload->getKey())
            ->where('upload.files.0.extraction.corrected_data.document_type', 'Invoice')
            ->where('upload.files.0.extraction.corrected_data.fields.0.label', 'Company Name'));
});

it('forbids editing unverified uploads or uploads belonging to another user', function (): void {
    [$uploader, $type, $upload] = verifiedUploadFixture();
    $otherUser = User::factory()->create();
    $type->users()->attach($otherUser->getKey(), ['is_active' => true]);

    // Unverified upload
    $upload->forceFill(['review_status' => ReviewStatus::Pending])->save();

    $this->actingAs($uploader)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->get(route('receiving.upload.history.edit', ['uploadType' => $type, 'upload' => $upload]))
        ->assertForbidden();

    // Reset status to verified, but try as another user
    $upload->forceFill(['review_status' => ReviewStatus::Verified])->save();

    $this->actingAs($otherUser)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->get(route('receiving.upload.history.edit', ['uploadType' => $type, 'upload' => $upload]))
        ->assertForbidden();
});

it('updates verified corrected data and logs activity', function (): void {
    [$uploader, $type, $upload, $extraction] = verifiedUploadFixture();

    $updated = [
        'document_type' => 'Official Receipt',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Corrected Supplier Inc'],
            ['label' => 'Gross', 'value' => '250.00'],
        ],
        'items' => [
            ['description' => 'Corrected Service', 'amount' => '250.00'],
        ],
    ];

    $this->actingAs($uploader)
        ->withSession(["receiving.otp_grants.{$type->getKey()}" => now()->getTimestamp()])
        ->put(route('receiving.upload.history.update', ['uploadType' => $type, 'upload' => $upload]), [
            'corrected_data' => [$extraction->getKey() => $updated],
        ])
        ->assertRedirect(route('receiving.upload.history', ['uploadType' => $type]));

    expect($extraction->refresh()->document_type)->toBe('Official Receipt')
        ->and($extraction->corrected_json['fields'][0]['value'])->toBe('Corrected Supplier Inc')
        ->and($extraction->review_status)->toBe(ReviewStatus::Verified)
        ->and($upload->refresh()->review_status)->toBe(ReviewStatus::Verified)
        ->and(ActivityLog::query()->where('action', 'scanned_data_corrected_by_uploader')->value('message'))
        ->toContain($uploader->email);
});

/** @return array{User, UploadType, ReceivingUpload, AiExtraction} */
function verifiedUploadFixture(): array
{
    $type = UploadType::query()->firstOrFail();
    $uploader = User::factory()->create();
    $type->users()->attach($uploader->getKey(), ['is_active' => true]);

    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(),
        'uploader_email' => $uploader->email,
        'processing_status' => 'completed',
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'review_status' => ReviewStatus::Verified,
    ]);

    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => 'invoice.pdf',
        'sanitized_file_name' => 'invoice.pdf',
        'stored_file_name' => 'invoice.pdf',
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => 'receiving/invoice.pdf',
        'r2_staging_object_key' => 'staging/invoice.pdf',
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Verified,
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
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice',
        'raw_extracted_json' => $data,
        'corrected_json' => $data,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Verified,
        'reviewed_at' => now(),
        'reviewed_by_email' => $uploader->email,
    ]);

    return [$uploader, $type, $upload, $extraction];
}
