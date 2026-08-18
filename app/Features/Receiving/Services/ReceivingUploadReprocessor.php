<?php

namespace App\Features\Receiving\Services;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Jobs\StartAiExtraction;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\ReceivingUpload;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivingUploadReprocessor
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly PurchaseOrderLinker $purchaseOrderLinks,
    ) {}

    public function queue(ReceivingUpload $upload, User $actor, Request $request): void
    {
        DB::transaction(function () use ($upload, $actor): void {
            $locked = ReceivingUpload::query()->with('uploadType')->lockForUpdate()->findOrFail($upload->getKey());

            if (in_array($locked->ai_status, [AiStatus::Pending, AiStatus::Processing], true)) {
                throw ValidationException::withMessages([
                    'reprocess' => 'AI processing is already pending or in progress for this serial number.',
                ]);
            }

            $fileIds = $locked->files()->whereNotNull('r2_object_key')->pluck('id');
            if ($fileIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'reprocess' => 'There are no accepted uploaded files to reprocess.',
                ]);
            }

            $reviewStatus = $locked->uploadType->workflow->requiresReview()
                ? ReviewStatus::Pending
                : ReviewStatus::NotRequired;
            $reviewEmailStatus = $locked->uploadType->workflow->requiresReview()
                ? EmailStatus::Pending
                : EmailStatus::NotRequired;

            PurchaseOrderDocumentLink::query()
                ->active()
                ->where(function ($query) use ($fileIds): void {
                    $query
                        ->whereHas('aiExtraction', fn ($extraction) => $extraction->whereIn('uploaded_file_id', $fileIds))
                        ->orWhereHas('poExtraction.aiExtraction', fn ($extraction) => $extraction->whereIn('uploaded_file_id', $fileIds));
                })
                ->get()
                ->each(function (PurchaseOrderDocumentLink $link) use ($actor): void {
                    $this->purchaseOrderLinks->unlink($link, $actor);
                });

            $locked->forceFill([
                'ai_status' => AiStatus::Pending,
                'review_status' => $reviewStatus,
                'review_email_status' => $reviewEmailStatus,
                'review_email_failure_reason' => null,
                'review_notification_sent_at' => null,
            ])->save();
            $locked->files()->whereIn('id', $fileIds)->update([
                'ai_status' => AiStatus::Pending->value,
                'review_status' => $reviewStatus->value,
                'failure_reason' => null,
            ]);
            AiExtraction::query()
                ->where('receiving_upload_id', $locked->getKey())
                ->whereIn('uploaded_file_id', $fileIds)
                ->update([
                    'document_type' => null,
                    'raw_extracted_json' => null,
                    'invoice_number' => null,
                    'po_number' => null,
                    'po_number_normalized' => null,
                    'po_date' => null,
                    'po_date_filled_from_po_extraction_id' => null,
                    'po_link_status' => PurchaseOrderLinkStatus::NotApplicable->value,
                    'corrected_json' => null,
                    'ai_status' => AiStatus::Pending->value,
                    'review_status' => $reviewStatus->value,
                    'failure_reason' => null,
                    'extracted_at' => null,
                    'reviewed_at' => null,
                    'reviewed_by_email' => null,
                ]);
            PoExtraction::query()
                ->where('receiving_upload_id', $locked->getKey())
                ->whereHas('aiExtraction', fn ($q) => $q->whereIn('uploaded_file_id', $fileIds))
                ->delete();
            $locked->reviewLinks()->whereNull('used_at')->update(['used_at' => now()]);

            StartAiExtraction::dispatch($locked->getKey())->afterCommit();
        });

        $upload->refresh()->loadMissing('uploadType');
        $this->activity->record(
            'ai',
            'admin_ai_reprocess_requested',
            'info',
            "{$actor->email} queued every accepted file under this serial number for AI reprocessing.",
            $actor,
            $upload,
            $request,
        );
    }
}
