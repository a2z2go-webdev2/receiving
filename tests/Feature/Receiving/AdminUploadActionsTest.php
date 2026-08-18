<?php

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Jobs\StartAiExtraction;
use App\Mail\ReceivingReviewReady;
use App\Mail\ReceivingUploadReceived;
use App\Models\AiExtraction;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('requires the admin otp grant on admin upload and file routes', function (): void {
    [$admin, $upload, $file] = adminUploadActionFixture();

    $this->actingAs($admin)
        ->get(route('admin.uploads.show', $upload))
        ->assertRedirect(route('admin.otp.show'));
    $this->postJson(route('receiving.files.url', $file))->assertForbidden();

    $this->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.show', $upload))
        ->assertOk();
});

it('shows purchase order details with the POSN serial format', function (): void {
    [$admin, $upload] = adminUploadActionFixture();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $upload->forceFill(['upload_type_id' => $purchaseOrderType->getKey()])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.uploads.show', $upload))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('upload.serial_prefix', 'POSN')
            ->where('upload.serial_number', 1));
});

it('does not expose upload details or file contents to the uploader', function (): void {
    [$admin, $upload, $file] = adminUploadActionFixture();
    $uploader = $upload->uploader;

    $this->actingAs($uploader)
        ->get("/receiving/uploads/{$upload->getKey()}")
        ->assertNotFound();
    $this->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->postJson(route('receiving.files.url', $file))
        ->assertForbidden();
});

it('shows corrected data in admin details only after verification', function (): void {
    [$admin, $upload, $file, $extraction] = adminUploadActionFixture();
    $session = ['admin.otp_verified_at' => now()->getTimestamp()];

    $this->actingAs($admin)
        ->withSession($session)
        ->get(route('admin.uploads.show', $upload))
        ->assertInertia(fn ($page) => $page
            ->missing('upload.processing_status')
            ->missing('upload.email_status')
            ->where('upload.review_email_status', EmailStatus::Pending->value)
            ->where('upload.location.latitude', 14.5995123)
            ->where('upload.location.longitude', 120.9842234)
            ->where('upload.location.accuracy_meters', 149)
            ->where('upload.files.0.extraction.corrected_data', null));

    $extraction->forceFill(['review_status' => ReviewStatus::Verified])->save();

    $this->withSession($session)
        ->get(route('admin.uploads.show', $upload))
        ->assertInertia(fn ($page) => $page
            ->where('upload.files.0.extraction.corrected_data.document_type', 'Invoice'));
});

it('reprocesses every accepted file while preserving receiving email state', function (): void {
    Queue::fake();
    Mail::fake();
    [$admin, $upload, $file, $extraction, $link] = adminUploadActionFixture();
    $upload->forceFill([
        'email_status' => EmailStatus::Failed,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Verified,
        'review_email_status' => EmailStatus::Sent,
    ])->save();
    $file->forceFill(['ai_status' => AiStatus::Extracted, 'review_status' => ReviewStatus::Verified])->save();
    $extraction->forceFill(['review_status' => ReviewStatus::Verified, 'reviewed_at' => now(), 'reviewed_by_email' => 'reviewer@example.com'])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.reprocess', $upload))
        ->assertSessionHas('status');

    expect($upload->refresh()->ai_status)->toBe(AiStatus::Pending)
        ->and($upload->review_status)->toBe(ReviewStatus::Pending)
        ->and($upload->review_email_status)->toBe(EmailStatus::Pending)
        ->and($upload->email_status)->toBe(EmailStatus::Failed)
        ->and($file->refresh()->ai_status)->toBe(AiStatus::Pending)
        ->and($file->review_status)->toBe(ReviewStatus::Pending)
        ->and($extraction->refresh()->raw_extracted_json)->toBeNull()
        ->and($extraction->corrected_json)->toBeNull()
        ->and($extraction->reviewed_by_email)->toBeNull()
        ->and($link->refresh()->used_at)->not->toBeNull();
    Queue::assertPushed(StartAiExtraction::class, fn (StartAiExtraction $job): bool => $job->uploadId === $upload->getKey());
    Mail::assertNotSent(ReceivingUploadReceived::class);
    Mail::assertNotSent(ReceivingReviewReady::class);
});

it('reprocesses purchase orders without reopening email or review states', function (): void {
    Queue::fake();
    [$admin, $upload, $file, $extraction, $link] = adminUploadActionFixture();
    $purchaseOrderType = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $upload->forceFill([
        'upload_type_id' => $purchaseOrderType->getKey(),
        'email_status' => EmailStatus::NotRequired,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::NotRequired,
        'review_email_status' => EmailStatus::NotRequired,
    ])->save();
    $file->forceFill([
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::NotRequired,
    ])->save();
    $extraction->forceFill(['review_status' => ReviewStatus::NotRequired])->save();
    $link->delete();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.reprocess', $upload))
        ->assertSessionHas('status');

    expect($upload->refresh()->ai_status)->toBe(AiStatus::Pending)
        ->and($upload->review_status)->toBe(ReviewStatus::NotRequired)
        ->and($upload->review_email_status)->toBe(EmailStatus::NotRequired)
        ->and($upload->email_status)->toBe(EmailStatus::NotRequired)
        ->and($file->refresh()->review_status)->toBe(ReviewStatus::NotRequired)
        ->and($extraction->refresh()->review_status)->toBe(ReviewStatus::NotRequired);
});

it('resends only the review email for a completed extraction', function (): void {
    Mail::fake();
    [$admin, $upload] = adminUploadActionFixture();
    $upload->forceFill([
        'email_status' => EmailStatus::Sent,
        'ai_status' => AiStatus::Extracted,
        'review_status' => ReviewStatus::Pending,
    ])->save();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->post(route('admin.uploads.resend-review', $upload))
        ->assertSessionHas('status');

    Mail::assertSent(ReceivingReviewReady::class);
    Mail::assertNotSent(ReceivingUploadReceived::class);
    expect($upload->refresh()->email_status)->toBe(EmailStatus::Sent)
        ->and($upload->review_email_status)->toBe(EmailStatus::Sent);
});

/** @return array{User, ReceivingUpload, UploadedFile, AiExtraction, ReviewLink} */
function adminUploadActionFixture(): array
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $uploader = User::factory()->create();
    $type = UploadType::query()->firstOrFail();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(), 'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $uploader->getKey(), 'uploader_email' => $uploader->email,
        'latitude' => 14.5995123, 'longitude' => 120.9842234,
        'location_accuracy_meters' => 149, 'location_captured_at' => now(),
        'r2_bucket' => 'test', 'r2_prefix' => 'receiving/test', 'file_count' => 1,
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'original_file_name' => 'document.pdf',
        'sanitized_file_name' => 'document.pdf', 'stored_file_name' => 'document.pdf',
        'file_extension' => 'pdf', 'r2_bucket' => 'test', 'r2_object_key' => 'receiving/document.pdf',
        'r2_staging_object_key' => 'staging/document.pdf', 'original_file_size' => 100,
        'final_file_size' => 100, 'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf', 'ai_status' => AiStatus::Extracted,
    ]);
    $data = [
        'document_type' => 'Invoice',
        'fields' => [['label' => 'Company Name', 'value' => 'ABC Supplier']],
        'items' => [],
    ];
    $extraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice', 'raw_extracted_json' => $data, 'corrected_json' => $data,
        'ai_status' => AiStatus::Extracted, 'extracted_at' => now(),
    ]);
    $link = ReviewLink::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'email' => $uploader->email,
        'upload_type_id' => $type->getKey(), 'token_hash' => hash('sha256', fake()->uuid()),
        'expires_at' => now()->addHour(),
    ]);

    return [$admin, $upload, $file, $extraction, $link];
}
