<?php

namespace App\Http\Controllers\Receiving;

use App\Enums\AiStatus;
use App\Enums\Permission;
use App\Enums\ReviewStatus;
use App\Enums\UploadWorkflow;
use App\Features\Receiving\Jobs\StartAiExtraction;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\InvoiceReviewValidator;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Features\Receiving\Services\UploadNotificationSender;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UploadRecordController extends Controller
{
    public function showAdmin(
        Request $request,
        ReceivingUpload $upload,
        ReceivingSettings $settings,
        InvoiceReviewValidator $validator,
        UploadSerialNumber $serials,
    ): Response {
        $this->authorize('view', $upload);

        return $this->renderDetails($request, $upload, $settings, $validator, $serials, 'admin/uploads/show');
    }

    private function renderDetails(
        Request $request,
        ReceivingUpload $upload,
        ReceivingSettings $settings,
        InvoiceReviewValidator $validator,
        UploadSerialNumber $serials,
        string $component,
    ): Response {
        $upload->load([
            'uploadType',
            'files.extraction.poExtraction.items',
            'files.extraction.poExtraction.activeDocumentLinks.aiExtraction.upload.uploadType',
            'files.extraction.activePurchaseOrderLink.poExtraction.upload',
        ]);
        $purchaseOrderCandidates = $upload->uploadType->workflow === UploadWorkflow::Standard
            ? $this->purchaseOrderCandidates()
            : [];

        return Inertia::render($component, [
            'uploadType' => $upload->uploadType->only(['id', 'name', 'slug']),
            'upload' => [
                'id' => $upload->getKey(),
                'serial_number' => $serials->number($upload),
                'serial_prefix' => $serials->prefix($upload->uploadType),
                'upload_type' => $upload->uploadType->name,
                'sends_notifications' => $upload->uploadType->workflow->sendsNotifications(),
                'requires_review' => $upload->uploadType->workflow->requiresReview(),
                'uploader_email' => $upload->uploader_email,
                'created_at' => $upload->created_at->toISOString(),
                'location' => $upload->latitude === null || $upload->longitude === null ? null : [
                    'latitude' => $upload->latitude,
                    'longitude' => $upload->longitude,
                    'accuracy_meters' => $upload->location_accuracy_meters,
                    'captured_at' => $upload->location_captured_at?->toISOString(),
                ],
                'review_email_status' => $upload->review_email_status->value,
                'ai_status' => $upload->ai_status->value,
                'review_status' => $upload->review_status->value,
                'can_resend' => $request->user()?->can('resendNotification', $upload) ?? false,
                'can_manage_purchase_order_links' => $request->user()?->can(Permission::RetryOperations->value) ?? false,
                'receiving_email_failed' => $upload->email_status->value === 'failed',
                'can_retry_ai' => in_array($upload->ai_status, [AiStatus::Failed, AiStatus::PartialFailed], true)
                    && ($request->user()?->can('retryExtraction', $upload) ?? false),
                'files' => $upload->files->map(fn (UploadedFile $file): array => [
                    'id' => $file->getKey(),
                    'name' => $file->original_file_name,
                    'content_type' => $file->content_type,
                    'size' => $file->final_file_size,
                    'virus_scan_status' => $file->virus_scan_status->value,
                    'failure_reason' => $file->failure_reason,
                    'extraction' => $file->extraction === null ? null : [
                        'document_type' => $file->extraction->document_type,
                        'id' => $file->extraction->getKey(),
                        'extracted_data' => $upload->uploadType->workflow === UploadWorkflow::PurchaseOrder
                            ? ($file->extraction->poExtraction ? $this->formatPo($file->extraction->poExtraction) : null)
                            : ($file->extraction->raw_extracted_json === null ? null : $validator->normalize($file->extraction->raw_extracted_json, false, $file->extraction)),
                        'corrected_data' => $upload->uploadType->workflow === UploadWorkflow::PurchaseOrder
                            ? null // POs do not have a manual review flow yet
                            : ($file->extraction->review_status !== ReviewStatus::Verified || $file->extraction->corrected_json === null
                                ? null : $validator->normalize($file->extraction->corrected_json, false, $file->extraction)),
                        'extracted_at' => $file->extraction->extracted_at?->toISOString(),
                        'reviewed_at' => $file->extraction->reviewed_at?->toISOString(),
                        'reviewed_by_email' => $file->extraction->reviewed_by_email,
                        ...$this->formatPurchaseOrderLinking(
                            $file->extraction,
                            $upload->uploadType->workflow,
                            $purchaseOrderCandidates,
                        ),
                    ],
                ])->values(),
            ],
        ]);
    }

    /** @param array<int, array{id: int, upload_id: int, po_number: string|null, po_date: string|null, vendor_name: string|null, uploaded_at: string}> $purchaseOrderCandidates */
    private function formatPurchaseOrderLinking(
        AiExtraction $extraction,
        UploadWorkflow $workflow,
        array $purchaseOrderCandidates,
    ): array {
        if ($workflow === UploadWorkflow::PurchaseOrder) {
            $poExtraction = $extraction->poExtraction;

            return [
                'purchase_order_status' => $poExtraction?->arrival_status->value,
                'purchase_order_linked_uploads' => $poExtraction?->activeDocumentLinks
                    ->map(fn (PurchaseOrderDocumentLink $link): array => $this->formatLinkedUpload($link))
                    ->values()
                    ->all() ?? [],
            ];
        }

        return [
            'po_number' => $extraction->po_number,
            'po_date' => $extraction->po_date,
            'po_link_status' => $extraction->po_link_status->value,
            'po_link' => $extraction->activePurchaseOrderLink
                ? $this->formatPoLink($extraction->activePurchaseOrderLink)
                : null,
            'po_link_candidates' => $purchaseOrderCandidates,
        ];
    }

    private function formatPoLink(PurchaseOrderDocumentLink $link): array
    {
        $po = $link->poExtraction;

        return [
            'id' => $link->getKey(),
            'po_extraction_id' => $po->getKey(),
            'po_upload_id' => $po->receiving_upload_id,
            'po_number' => $po->po_number,
            'po_date' => $po->po_date,
            'vendor_name' => $po->vendor_name,
            'source' => $link->source->value,
            'linked_at' => $link->created_at->toISOString(),
        ];
    }

    private function formatLinkedUpload(PurchaseOrderDocumentLink $link): array
    {
        $extraction = $link->aiExtraction;
        $upload = $extraction->upload;

        return [
            'id' => $upload->getKey(),
            'serial_number' => $upload->getKey(),
            'upload_type' => $upload->uploadType->name,
            'document_type' => $extraction->document_type,
            'source' => $link->source->value,
            'linked_at' => $link->created_at->toISOString(),
        ];
    }

    /** @return array<int, array{id: int, upload_id: int, po_number: string|null, po_date: string|null, vendor_name: string|null, uploaded_at: string}> */
    private function purchaseOrderCandidates(): array
    {
        return PoExtraction::query()
            ->with('upload:id,created_at')
            ->orderByDesc('po_date_value')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (PoExtraction $po): array => [
                'id' => (int) $po->getKey(),
                'upload_id' => $po->receiving_upload_id,
                'po_number' => $po->po_number,
                'po_date' => $po->po_date,
                'vendor_name' => $po->vendor_name,
                'uploaded_at' => (string) $po->upload->created_at->toISOString(),
            ])
            ->all();
    }

    private function formatPo(PoExtraction $po): array
    {
        $requiredLabels = [
            'PO Number', 'PO Reference', 'PO Date', 'Buyer Company',
            'Vendor Name', 'Contact Person', 'Payment Terms',
        ];

        $fields = collect([
            'PO Number' => $po->po_number,
            'PO Reference' => $po->po_reference,
            'PO Date' => $po->po_date,
            'Buyer Company' => $po->buyer_company,
            'Buyer Address' => $po->buyer_address,
            'Buyer Contact Numbers' => $po->buyer_contact_numbers,
            'Vendor Name' => $po->vendor_name,
            'Contact Person' => $po->contact_person,
            'Vendor Email' => $po->vendor_email,
            'Vendor Mobile' => $po->vendor_mobile,
            'Vendor Address' => $po->vendor_address,
            'Payment Terms' => $po->payment_terms,
            'Subtotal' => $po->subtotal,
            'VAT' => $po->vat,
            'Total Amount' => $po->total_amount,
        ])->map(function (?string $value, string $label) use ($requiredLabels): array {
            if ($value !== null && $value !== '') {
                return ['label' => $label, 'value' => $value];
            }

            return [
                'label' => $label,
                'value' => in_array($label, $requiredLabels, true) ? '[See image]' : '',
            ];
        })->values()->all();

        $items = $po->items->map(fn ($item): array => [
            'itemCode' => $item->item_code ?? '[See image]',
            'productDescription' => $item->product_description ?? '[See image]',
            'package' => $item->package ?? '',
            'quantity' => $item->quantity ?? '[See image]',
            'unit' => $item->unit ?? '[See image]',
            'unitPrice' => $item->unit_price ?? '[See image]',
            'lineTotal' => $item->line_total ?? '[See image]',
        ])->all();

        return [
            'document_type' => 'Purchase Order',
            'fields' => $fields,
            'items' => $items,
        ];
    }

    public function resend(
        Request $request,
        ReceivingUpload $upload,
        UploadNotificationSender $notifications,
    ): RedirectResponse {
        $this->authorize('resendNotification', $upload);

        if ($notifications->send($upload, $request->user(), 'upload_notification_resent')) {
            return back()->with('status', 'Email notification was resent successfully.');
        }

        return back()->withErrors(['email' => 'Email notification could not be resent. Please try again later or contact the Admin.']);
    }

    public function retryAi(
        Request $request,
        ReceivingUpload $upload,
        ActivityLogger $activity,
    ): RedirectResponse {
        $this->authorize('retryExtraction', $upload);
        abort_unless(
            in_array($upload->ai_status, [AiStatus::Failed, AiStatus::PartialFailed], true),
            422,
            'Only failed AI processing may be retried.',
        );

        StartAiExtraction::dispatch($upload->getKey(), true)->afterCommit();
        $isAdmin = $request->user()?->can(Permission::RetryOperations->value) ?? false;
        $activity->record(
            'ai',
            $isAdmin ? 'admin_ai_retry_requested' : 'user_ai_retry_requested',
            'info',
            ($isAdmin ? 'Administrator ' : 'User ').$request->user()?->email.' requested a retry of failed AI processing.',
            $request->user(),
            $upload,
            $request,
        );

        return back()->with('status', 'Failed AI processing was queued for retry.');
    }

    public function fileUrl(
        Request $request,
        UploadedFile $file,
        ReceivingSettings $settings,
        ActivityLogger $activity,
    ): JsonResponse {
        $file->loadMissing('upload');
        $this->authorize('view', $file);
        abort_if($file->r2_object_key === null, 404);

        try {
            $url = Storage::disk((string) config('receiving.disk'))->temporaryUrl(
                $file->r2_object_key,
                now()->addMinutes((int) $settings->get('signed_url_expiration_minutes')),
            );
        } catch (Throwable) {
            $url = route('receiving.files.stream', $file);
        }

        $activity->record('r2_storage', 'temporary_file_link_generated', 'success', "Temporary access generated for file {$file->getKey()}.", $request->user(), $file->upload, $request);

        return response()->json(['url' => $url]);
    }

    public function stream(Request $request, UploadedFile $file): StreamedResponse
    {
        $file->loadMissing('upload');
        $this->authorize('view', $file);
        abort_if($file->r2_object_key === null, 404);

        return Storage::disk((string) config('receiving.disk'))->response(
            $file->r2_object_key,
            $file->sanitized_file_name,
            ['Content-Type' => $file->content_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
