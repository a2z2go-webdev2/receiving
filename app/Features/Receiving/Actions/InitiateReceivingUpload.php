<?php

namespace App\Features\Receiving\Actions;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\ReviewStatus;
use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\FileNameSanitizer;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitiateReceivingUpload
{
    public function __construct(
        private readonly FileNameSanitizer $sanitizer,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<int, array{name: string, size: int, content_type: string, extension: string}>  $files
     * @param  array{latitude: float|int|string, longitude: float|int|string, accuracy: float|int|string, captured_at: string}|null  $location
     */
    public function handle(User $user, UploadType $uploadType, array $files, ?string $ip = null, ?string $submissionId = null, ?array $location = null): ReceivingUpload
    {
        abort_unless($user->canAccessUploadType($uploadType), 403);
        $submissionId ??= (string) Str::uuid();

        $existing = ReceivingUpload::query()
            ->where('submission_id', $submissionId)
            ->where('uploader_user_id', $user->getKey())
            ->where('upload_type_id', $uploadType->getKey())
            ->with(['files', 'uploadType'])
            ->first();
        if ($existing) {
            return $existing;
        }

        $upload = DB::transaction(function () use ($user, $uploadType, $files, $submissionId, $location): ReceivingUpload {
            $requiresReview = $uploadType->workflow->requiresReview();
            $sendsNotifications = $uploadType->workflow->sendsNotifications();
            $nextSerial = ((int) ReceivingUpload::query()
                ->where('upload_type_id', $uploadType->getKey())
                ->max('serial_number')) + 1;

            $upload = ReceivingUpload::query()->create([
                'submission_id' => $submissionId,
                'upload_type_id' => $uploadType->getKey(),
                'serial_number' => $nextSerial,
                'uploader_user_id' => $user->getKey(),
                'uploader_email' => $user->email,
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'location_accuracy_meters' => $location['accuracy'] ?? null,
                'location_captured_at' => $location['captured_at'] ?? null,
                'r2_bucket' => config('receiving.bucket'),
                'file_count' => count($files),
                'processing_status' => UploadProcessingStatus::Staging,
                'email_status' => $sendsNotifications ? EmailStatus::Pending : EmailStatus::NotRequired,
                'review_email_status' => $requiresReview ? EmailStatus::Pending : EmailStatus::NotRequired,
                'ai_status' => AiStatus::Pending,
                'review_status' => $requiresReview ? ReviewStatus::Pending : ReviewStatus::NotRequired,
            ]);

            $upload->r2_prefix = sprintf(
                'receiving/%s/%s/SN-%d/',
                $uploadType->r2_prefix,
                $upload->created_at->format('Y/m/d'),
                $nextSerial,
            );
            $upload->save();

            foreach ($files as $index => $metadata) {
                $sanitized = $this->sanitizer->sanitize($metadata['name']);
                $extension = strtolower(pathinfo($metadata['name'], PATHINFO_EXTENSION));
                $stored = $this->sanitizer->storedName(
                    $uploadType->r2_prefix,
                    $nextSerial,
                    $index + 1,
                    $extension,
                );
                /** @var UploadedFile $file */
                $file = $upload->files()->create([
                    'original_file_name' => $metadata['name'],
                    'sanitized_file_name' => $sanitized,
                    'stored_file_name' => $stored,
                    'file_extension' => $extension,
                    'r2_bucket' => config('receiving.bucket'),
                    'r2_staging_object_key' => 'pending/'.Str::uuid(),
                    'original_file_size' => $metadata['size'],
                    'declared_content_type' => $metadata['content_type'],
                    'review_status' => $requiresReview ? ReviewStatus::Pending : ReviewStatus::NotRequired,
                ]);
                $file->r2_staging_object_key = sprintf(
                    'staging/%s/SN-%d/%s',
                    $uploadType->r2_prefix,
                    $nextSerial,
                    $stored,
                );
                $file->save();
            }

            return $upload->load('files', 'uploadType');
        });

        $this->activity->record('upload', 'upload_started', 'info', 'Upload transaction created and awaiting staged objects.', $user, $upload, $ip);

        return $upload;
    }
}
