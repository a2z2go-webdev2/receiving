<?php

namespace App\Features\Receiving\Jobs;

use App\Enums\ValidationStatus;
use App\Enums\VirusScanStatus;
use App\Features\Receiving\Exceptions\MalwareScanDeferred;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\FileAcceptancePipeline;
use App\Models\UploadedFile;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessUploadedFile implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 180];

    public function __construct(public readonly int $fileId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("receiving-file-{$this->fileId}"))->expireAfter(600)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(40);
    }

    public function handle(FileAcceptancePipeline $pipeline, ActivityLogger $activity): void
    {
        $file = UploadedFile::query()->with('upload.uploadType')->findOrFail($this->fileId);

        if ($file->r2_object_key !== null) {
            return;
        }

        try {
            $pipeline->process($file);
            $activity->record('upload', 'file_accepted', 'success', "{$file->sanitized_file_name} completed validation, virus scanning, compression, and storage.", null, $file->upload);
        } catch (MalwareScanDeferred $error) {
            $activity->record(
                'upload',
                'file_processing_deferred',
                'warning',
                "Backend processing for {$file->sanitized_file_name} is paused by the malware scanner and will retry automatically.",
                null,
                $file->upload,
            );
            $this->release($error->retryAfterSeconds);
        } catch (Throwable $error) {
            $activity->record('upload', 'file_processing_failed', 'error', "Backend processing failed for {$file->sanitized_file_name}.", null, $file->upload, null, $error);
            $file->refresh();
            if ($file->validation_status === ValidationStatus::Invalid
                || in_array($file->virus_scan_status, [VirusScanStatus::Infected, VirusScanStatus::Suspicious], true)) {
                return;
            }
            throw $error;
        }
    }
}
