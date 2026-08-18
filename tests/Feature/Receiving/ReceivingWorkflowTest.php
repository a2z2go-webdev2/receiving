<?php

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\ReviewStatus;
use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Actions\InitiateReceivingUpload;
use App\Features\Receiving\Jobs\ProcessReceivingUpload;
use App\Features\Receiving\Jobs\StartAiExtraction;
use App\Mail\ReceivingReviewReady;
use App\Mail\ReceivingUploadReceived;
use App\Models\EmailRecipient;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(UploadTypeSeeder::class);
    Storage::fake('r2');
    Mail::fake();
    config()->set('receiving.disk', 'r2');
});

it('processes an accepted upload through notification extraction and review email', function (): void {
    $upload = workflowUpload();
    $type = $upload->uploadType;
    EmailRecipient::query()->create([
        'upload_type_id' => $type->getKey(),
        'email' => 'receiving@example.com',
        'type' => 'to',
        'is_active' => true,
    ]);

    app()->call([new ProcessReceivingUpload($upload->getKey()), 'handle']);

    $upload->refresh();
    expect($upload->processing_status)->toBe(UploadProcessingStatus::Completed)
        ->and($upload->email_status)->toBe(EmailStatus::Sent)
        ->and($upload->review_email_status)->toBe(EmailStatus::Sent)
        ->and($upload->ai_status)->toBe(AiStatus::Extracted)
        ->and($upload->files()->firstOrFail()->ai_status)->toBe(AiStatus::Extracted)
        ->and($upload->extractions()->count())->toBe(1)
        ->and($upload->extractions()->firstOrFail()->corrected_json)->toBeNull()
        ->and(ReviewLink::query()->where('receiving_upload_id', $upload->getKey())->exists())->toBeTrue();

    Mail::assertSent(ReceivingUploadReceived::class, 1);
    Mail::assertSent(ReceivingReviewReady::class, 1);

    app()->call([new ProcessReceivingUpload($upload->getKey()), 'handle']);
    Mail::assertSent(ReceivingUploadReceived::class, 1);
    Mail::assertSent(ReceivingReviewReady::class, 1);
});

it('sends both receiving and review notifications to the uploader without configured recipients', function (): void {
    $upload = workflowUpload();

    app()->call([new ProcessReceivingUpload($upload->getKey()), 'handle']);

    $upload->refresh();
    expect($upload->processing_status)->toBe(UploadProcessingStatus::Completed)
        ->and($upload->email_status)->toBe(EmailStatus::Sent)
        ->and($upload->review_email_status)->toBe(EmailStatus::Sent)
        ->and($upload->ai_status)->toBe(AiStatus::Extracted);

    Mail::assertSent(
        ReceivingUploadReceived::class,
        fn (ReceivingUploadReceived $mail): bool => $mail->hasTo($upload->uploader_email),
    );
    Mail::assertSent(
        ReceivingReviewReady::class,
        fn (ReceivingReviewReady $mail): bool => $mail->hasTo($upload->uploader_email),
    );
});

it('extracts purchase orders without sending email or creating a review link', function (): void {
    $upload = purchaseOrderWorkflowUpload();

    app()->call([new ProcessReceivingUpload($upload->getKey()), 'handle']);

    $upload->refresh();
    expect($upload->processing_status)->toBe(UploadProcessingStatus::Completed)
        ->and($upload->email_status)->toBe(EmailStatus::NotRequired)
        ->and($upload->review_email_status)->toBe(EmailStatus::NotRequired)
        ->and($upload->review_status)->toBe(ReviewStatus::NotRequired)
        ->and($upload->ai_status)->toBe(AiStatus::Extracted)
        ->and($upload->files()->firstOrFail()->review_status)->toBe(ReviewStatus::NotRequired)
        ->and($upload->extractions()->firstOrFail()->review_status)->toBe(ReviewStatus::NotRequired)
        ->and(ReviewLink::query()->where('receiving_upload_id', $upload->getKey())->exists())->toBeFalse();

    Mail::assertNothingSent();
});

it('bounds each AI job to the configured worker execution budget', function (): void {
    Bus::fake();
    config()->set([
        'receiving.ai.http_attempts' => 2,
        'receiving.queue.workload_timeout_seconds' => 300,
        'receiving.queue.timeout_safety_seconds' => 30,
        'services.gemini.timeout_seconds' => 120,
    ]);
    $upload = workflowUpload();
    $original = $upload->files()->firstOrFail();
    $original->forceFill(['r2_object_key' => 'accepted/one.jpg'])->save();
    foreach (['two.jpg', 'three.jpg'] as $name) {
        $upload->files()->create([
            'original_file_name' => $name,
            'sanitized_file_name' => $name,
            'stored_file_name' => $name,
            'file_extension' => 'jpg',
            'r2_bucket' => 'test',
            'r2_staging_object_key' => "staging/{$name}",
            'r2_object_key' => "accepted/{$name}",
            'original_file_size' => 100,
            'declared_content_type' => 'image/jpeg',
        ]);
    }
    $upload->forceFill(['file_count' => 3, 'ai_status' => AiStatus::Pending])->save();

    app()->call([new StartAiExtraction($upload->getKey()), 'handle']);

    Bus::assertBatched(fn (PendingBatch $batch): bool => count($batch->jobs) === 3
        && collect($batch->jobs)->every(fn ($job): bool => count($job->fileIds) === 1));
});

it('persists terminal failure states when orchestration retries are exhausted', function (): void {
    $upload = workflowUpload();
    (new ProcessReceivingUpload($upload->getKey()))->failed(new RuntimeException('queue unavailable'));

    expect($upload->fresh()->processing_status)->toBe(UploadProcessingStatus::Failed)
        ->and($upload->fresh()->failure_reason)->toContain('after retries');

    $upload->forceFill(['ai_status' => AiStatus::Processing, 'failure_reason' => null])->save();
    (new StartAiExtraction($upload->getKey()))->failed(new RuntimeException('batch unavailable'));

    expect($upload->fresh()->ai_status)->toBe(AiStatus::Failed)
        ->and($upload->fresh()->failure_reason)->toContain('after retries');
});

function workflowUpload(): ReceivingUpload
{
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $image = HttpUploadedFile::fake()->image('invoice.jpg', 120, 120);
    $bytes = file_get_contents($image->getPathname());
    expect($bytes)->toBeString();

    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'invoice.jpg',
        'size' => strlen($bytes),
        'content_type' => 'image/jpeg',
        'extension' => 'jpg',
    ]]);
    Storage::disk('r2')->put($upload->files->firstOrFail()->r2_staging_object_key, $bytes);
    $upload->files()->update(['uploaded_at' => now()]);
    $upload->forceFill([
        'processing_status' => UploadProcessingStatus::Queued,
        'upload_completed_at' => now(),
    ])->save();

    return $upload;
}

function purchaseOrderWorkflowUpload(): ReceivingUpload
{
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', 'purchase-order')->firstOrFail();
    $user->uploadAccesses()->create(['upload_type_id' => $type->getKey(), 'is_active' => true]);
    $bytes = '%PDF-1.7 purchase order test';

    $upload = app(InitiateReceivingUpload::class)->handle($user, $type, [[
        'name' => 'purchase-order.pdf',
        'size' => strlen($bytes),
        'content_type' => 'application/pdf',
        'extension' => 'pdf',
    ]]);
    Storage::disk('r2')->put($upload->files->firstOrFail()->r2_staging_object_key, $bytes);
    $upload->files()->update(['uploaded_at' => now()]);
    $upload->forceFill([
        'processing_status' => UploadProcessingStatus::Queued,
        'upload_completed_at' => now(),
    ])->save();

    return $upload;
}
