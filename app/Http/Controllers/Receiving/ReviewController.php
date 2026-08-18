<?php

namespace App\Http\Controllers\Receiving;

use App\Enums\AiStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\InvoiceReviewValidator;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Features\Receiving\Services\ReviewLinkService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receiving\SaveExtractionReviewRequest;
use App\Http\Requests\Receiving\VerifyReceivingReviewRequest;
use App\Models\AiExtraction;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use App\Models\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ReviewController extends Controller
{
    public function show(
        Request $request,
        string $token,
        ReviewLinkService $links,
        ReceivingSettings $settings,
        InvoiceReviewValidator $validator,
        ActivityLogger $activity,
    ): Response {
        $link = $this->usableLink($token, $links);
        /** @var ReceivingUpload $upload */
        $upload = $link->upload()->with(['uploadType', 'files.extraction'])->firstOrFail();
        $activity->record('review', 'review_link_opened', 'info', "Secure review link was opened by {$link->email}.", null, $upload, $request);

        return Inertia::render('review/show', [
            'token' => $token,
            'reviewerEmail' => $link->email,
            'upload' => [
                'serial_number' => $upload->getKey(),
                'upload_type' => $upload->uploadType->name,
                'uploader_email' => $upload->uploader_email,
                'created_at' => $upload->created_at->toISOString(),
                'review_status' => $upload->review_status->value,
                'files' => $upload->files->whereNotNull('extraction')->map(fn (UploadedFile $file): array => [
                    'id' => $file->getKey(),
                    'name' => $file->original_file_name,
                    'content_type' => $file->content_type,
                    'preview_url' => $this->previewUrl($token, $file, $settings),
                    'extraction' => [
                        'id' => $file->extraction->getKey(),
                        'document_type' => $file->extraction->document_type,
                        'corrected_data' => $validator->normalize(
                            (array) ($file->extraction->corrected_json ?? $file->extraction->raw_extracted_json),
                            false,
                            $file->extraction
                        ),
                        'review_status' => $file->extraction->review_status->value,
                    ],
                ])->values(),
            ],
        ]);
    }

    public function update(
        SaveExtractionReviewRequest $request,
        string $token,
        AiExtraction $extraction,
        ReviewLinkService $links,
        InvoiceReviewValidator $validator,
        PurchaseOrderLinker $purchaseOrderLinks,
        ActivityLogger $activity,
    ): RedirectResponse {
        $link = $this->usableLink($token, $links);
        abort_unless($extraction->receiving_upload_id === $link->receiving_upload_id, 404);
        $corrected = $request->validated('corrected_data');
        $corrected = $validator->normalize($corrected, false);
        $extraction->forceFill([
            'document_type' => $corrected['document_type'],
            'corrected_json' => $corrected,
            'review_status' => ReviewStatus::Revision,
            'reviewed_by_email' => $link->email,
        ])->save();
        $extraction->file->forceFill(['review_status' => ReviewStatus::Revision])->save();
        $extraction->upload->forceFill(['review_status' => ReviewStatus::Revision])->save();
        $purchaseOrderLinks->syncExtraction($extraction);
        $activity->record('review', 'review_corrections_saved', 'success', "{$link->email} saved corrections for {$extraction->file->sanitized_file_name}.", null, $extraction->upload, $request);

        return back()->with('status', 'Corrections saved.');
    }

    public function verify(
        VerifyReceivingReviewRequest $request,
        string $token,
        ReviewLinkService $links,
        InvoiceReviewValidator $validator,
        PurchaseOrderLinker $purchaseOrderLinks,
        ActivityLogger $activity,
    ): RedirectResponse {
        $link = $this->usableLink($token, $links);
        /** @var ReceivingUpload $upload */
        $upload = $link->upload()->with('extractions.file')->firstOrFail();
        abort_if($upload->extractions->isEmpty(), 422, 'There is no extracted data to verify.');
        abort_if(
            $upload->extractions->contains(fn (AiExtraction $extraction): bool => $extraction->ai_status !== AiStatus::Extracted || $extraction->raw_extracted_json === null
            ),
            422,
            'Every accepted file must have extracted data before this review can be verified.',
        );

        /** @var array<int|string, array<string, mixed>> $submittedCorrections */
        $submittedCorrections = $request->validated('corrected_data', []);
        if ($submittedCorrections !== []) {
            $expectedIds = $upload->extractions
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $submittedIds = array_map(
                fn (int|string $id): int => is_numeric($id) ? (int) $id : 0,
                array_keys($submittedCorrections),
            );
            if (array_diff($expectedIds, $submittedIds) !== []
                || array_diff($submittedIds, $expectedIds) !== []) {
                throw ValidationException::withMessages([
                    'corrected_data' => 'Corrected data must be included for every scanned file before final verification.',
                ]);
            }
        }

        abort_if(
            $submittedCorrections === []
                && $upload->extractions->contains(fn (AiExtraction $extraction): bool => $extraction->corrected_json === null),
            422,
            'Every accepted file must have reviewed data before this review can be verified.',
        );

        DB::transaction(function () use ($upload, $link, $validator, $purchaseOrderLinks, $submittedCorrections): void {
            foreach ($upload->extractions as $extraction) {
                $corrected = $submittedCorrections === []
                    ? (array) $extraction->corrected_json
                    : $submittedCorrections[$extraction->getKey()];
                $corrected = $validator->normalize($corrected, true);
                $extraction->forceFill([
                    'document_type' => $corrected['document_type'],
                    'corrected_json' => $corrected,
                    'review_status' => ReviewStatus::Verified,
                    'reviewed_at' => now(),
                    'reviewed_by_email' => $link->email,
                ])->save();
                $extraction->file->forceFill(['review_status' => ReviewStatus::Verified])->save();
                $purchaseOrderLinks->syncExtraction($extraction);
            }

            $upload->forceFill(['review_status' => ReviewStatus::Verified])->save();
            $upload->reviewLinks()->whereNull('used_at')->update(['used_at' => now()]);
        });
        $activity->record('review', 'scanned_data_verified', 'success', "Scanned data was reviewed and verified by {$link->email}. The verified data is ready for reporting.", null, $upload, $request);

        return redirect()->route('receiving.review.completed');
    }

    private function usableLink(string $token, ReviewLinkService $links): ReviewLink
    {
        $link = $links->resolve($token);
        abort_unless($link?->isUsable(), 410, 'This review link has expired. Please request a new review link.');

        return $link;
    }

    private function previewUrl(string $token, UploadedFile $file, ReceivingSettings $settings): string
    {
        try {
            return Storage::disk((string) config('receiving.disk'))->temporaryUrl(
                (string) $file->r2_object_key,
                now()->addMinutes((int) $settings->get('signed_url_expiration_minutes')),
            );
        } catch (Throwable) {
            return route('receiving.review.file', ['token' => $token, 'file' => $file->getKey()]);
        }
    }
}
