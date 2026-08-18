<?php

namespace App\Features\Receiving\Jobs;

use App\Enums\AiStatus;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\ReviewLinkService;
use App\Features\Receiving\Services\UploadNotificationSender;
use App\Models\ReceivingUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class FinalizeAiExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $uploadId)
    {
        $this->onQueue('ai');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("ai-finalize-{$this->uploadId}"))->expireAfter(300)];
    }

    public function handle(ReviewLinkService $links, UploadNotificationSender $notifications, ActivityLogger $activity): void
    {
        $upload = ReceivingUpload::query()->with(['files', 'uploadType.recipients'])->findOrFail($this->uploadId);
        $eligible = $upload->files->whereNotNull('r2_object_key');
        $extracted = $eligible->where('ai_status', AiStatus::Extracted);
        if ($upload->ai_status === AiStatus::Processing) {
            $status = match (true) {
                $extracted->count() === $eligible->count() => AiStatus::Extracted,
                $extracted->isNotEmpty() => AiStatus::PartialFailed,
                default => AiStatus::Failed,
            };

            $upload->forceFill(['ai_status' => $status])->save();
            $activity->record(
                'ai',
                'ai_processing_completed',
                $extracted->count() === $eligible->count() ? 'success' : 'error',
                "AI processing completed for {$extracted->count()} of {$eligible->count()} accepted files.",
                null,
                $upload,
            );
        }

        if ($upload->uploadType->workflow->sendsNotifications() && $upload->email_status->value !== 'sent') {
            $notifications->send($upload);
        }

        if ($upload->uploadType->workflow->requiresReview()
            && $upload->ai_status === AiStatus::Extracted
            && $upload->review_email_status->value !== 'sent') {
            $links->issueAndSend($upload);
        }
    }
}
