<?php

namespace App\Features\Receiving\Jobs;

use App\Enums\UploadProcessingStatus;
use App\Enums\ValidationStatus;
use App\Enums\VirusScanStatus;
use App\Features\Receiving\Services\ActivityLogger;
use App\Models\ReceivingUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class FinalizeReceivingUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $uploadId)
    {
        $this->onQueue('receiving');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("receiving-finalize-{$this->uploadId}"))->expireAfter(300)];
    }

    public function handle(ActivityLogger $activity): void
    {
        $upload = ReceivingUpload::query()->with(['files', 'uploadType.recipients'])->findOrFail($this->uploadId);
        $accepted = $upload->files->filter(fn ($file): bool => $file->validation_status === ValidationStatus::Valid
            && $file->virus_scan_status === VirusScanStatus::Clean
            && $file->r2_object_key !== null
        );

        if ($upload->processing_status === UploadProcessingStatus::Processing) {
            $status = match (true) {
                $accepted->count() === $upload->file_count => UploadProcessingStatus::Completed,
                $accepted->isNotEmpty() => UploadProcessingStatus::PartialFailed,
                default => UploadProcessingStatus::Failed,
            };
            $upload->forceFill(['processing_status' => $status])->save();
            $activity->record(
                'upload',
                'upload_processing_completed',
                $accepted->count() === $upload->file_count ? 'success' : 'error',
                "Backend processing completed for {$accepted->count()} of {$upload->file_count} files.",
                null,
                $upload,
            );
        }

        if ($accepted->isEmpty()) {
            return;
        }

        if ($upload->ai_status->value === 'pending') {
            StartAiExtraction::dispatch($upload->getKey());
        }
    }
}
