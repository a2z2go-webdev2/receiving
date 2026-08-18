<?php

namespace App\Features\Receiving\Jobs;

use App\Enums\AiStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Models\AiExtraction;
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

class StartAiExtraction implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $uploadId, public readonly bool $retryFailed = false)
    {
        $this->onQueue('ai');
    }

    public function handle(ActivityLogger $activity): void
    {
        $eligibleStatuses = $this->retryFailed
            ? [AiStatus::Failed, AiStatus::PartialFailed]
            : [AiStatus::Pending];
        $upload = ReceivingUpload::query()->with(['files', 'uploadType'])->findOrFail($this->uploadId);
        if ($upload->ai_status !== AiStatus::Processing && ! in_array($upload->ai_status, $eligibleStatuses, true)) {
            return;
        }

        if ($upload->ai_status !== AiStatus::Processing) {
            $upload->forceFill(['ai_status' => AiStatus::Processing])->save();
        }

        $files = $upload->files->whereNotNull('r2_object_key');

        foreach ($files as $file) {
            AiExtraction::query()->firstOrCreate(
                ['uploaded_file_id' => $file->getKey()],
                [
                    'receiving_upload_id' => $upload->getKey(),
                    'ai_status' => AiStatus::Pending,
                    'review_status' => $upload->uploadType->workflow->requiresReview()
                        ? ReviewStatus::Pending
                        : ReviewStatus::NotRequired,
                ],
            );

            if ($this->retryFailed && $file->ai_status === AiStatus::Failed) {
                $file->forceFill(['ai_status' => AiStatus::Pending, 'failure_reason' => null])->save();
                $file->extraction?->forceFill(['ai_status' => AiStatus::Pending, 'failure_reason' => null])->save();
            }
        }

        $activity->record(
            'ai',
            $this->retryFailed ? 'ai_retry_started' : 'ai_processing_started',
            'info',
            ($this->retryFailed ? 'Retrying failed AI processing for ' : 'AI processing started for ').$files->count().' accepted '.str('file')->plural($files->count()).'.',
            null,
            $upload,
        );
        $batchSize = $this->safeBatchSize();
        $jobs = $files->pluck('id')->chunk($batchSize)
            ->map(fn ($ids): ExtractReceivingBatch => new ExtractReceivingBatch($ids->values()->all()))
            ->all();

        Bus::batch($jobs)
            ->name("Receiving SN-{$upload->getKey()} AI extraction")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($upload): void {
                FinalizeAiExtraction::dispatch($upload->getKey());
            })
            ->onQueue('ai')
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
                ->where('ai_status', AiStatus::Processing)
                ->update([
                    'ai_status' => AiStatus::Failed,
                    'failure_reason' => 'AI processing could not be started after retries.',
                    'updated_at' => now(),
                ]);
            app(ActivityLogger::class)->record('ai', 'ai_processing_failed', 'error', 'AI processing could not be started.', null, $upload, null, $error);
        }
    }

    private function safeBatchSize(): int
    {
        $configured = max(1, (int) app(ReceivingSettings::class)->get('ai_batch_size'));
        $workerSeconds = max(1, (int) config('receiving.queue.workload_timeout_seconds', 300));
        $safetySeconds = max(0, (int) config('receiving.queue.timeout_safety_seconds', 30));
        $providerSeconds = max(1, (int) config('services.gemini.timeout_seconds', 120));
        $httpAttempts = max(1, (int) config('receiving.ai.http_attempts', 2));
        $perFileBudget = $providerSeconds * $httpAttempts;
        $usableBudget = max(1, $workerSeconds - $safetySeconds);
        $safeMaximum = max(1, intdiv($usableBudget, $perFileBudget));

        return min($configured, $safeMaximum);
    }
}
