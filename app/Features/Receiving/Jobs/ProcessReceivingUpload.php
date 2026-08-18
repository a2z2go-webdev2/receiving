<?php

namespace App\Features\Receiving\Jobs;

use App\Enums\UploadProcessingStatus;
use App\Features\Receiving\Services\ActivityLogger;
use App\Models\ReceivingUpload;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ProcessReceivingUpload implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $uploadId)
    {
        $this->onQueue('receiving');
    }

    public function handle(ActivityLogger $activity): void
    {
        $upload = ReceivingUpload::query()->with(['files', 'uploadType'])->findOrFail($this->uploadId);
        if (! in_array($upload->processing_status, [UploadProcessingStatus::Queued, UploadProcessingStatus::Processing], true)) {
            return;
        }

        if ($upload->processing_status === UploadProcessingStatus::Queued) {
            $upload->forceFill(['processing_status' => UploadProcessingStatus::Processing])->save();
        }

        $activity->record('upload', 'upload_processing_started', 'info', "Backend processing started for {$upload->file_count} ".str('file')->plural($upload->file_count).'.', null, $upload);
        $jobs = $upload->files
            ->map(fn ($file): ProcessUploadedFile => new ProcessUploadedFile($file->getKey()))
            ->all();

        Bus::batch($jobs)
            ->name("Receiving SN-{$upload->getKey()} file acceptance")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($upload): void {
                FinalizeReceivingUpload::dispatch($upload->getKey());
            })
            ->onQueue('receiving')
            ->dispatch();
    }

    public function uniqueId(): string
    {
        return (string) $this->uploadId;
    }

    public function failed(?Throwable $error): void
    {
        $upload = ReceivingUpload::query()->with('uploadType')->find($this->uploadId);
        if ($upload !== null) {
            ReceivingUpload::query()
                ->whereKey($this->uploadId)
                ->whereIn('processing_status', [UploadProcessingStatus::Queued, UploadProcessingStatus::Processing])
                ->update([
                    'processing_status' => UploadProcessingStatus::Failed,
                    'failure_reason' => 'Backend upload processing could not be started after retries.',
                    'updated_at' => now(),
                ]);
            app(ActivityLogger::class)->record('upload', 'upload_processing_failed', 'error', 'Backend upload processing could not be started.', null, $upload, null, $error);
        }
    }
}
