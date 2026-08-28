<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\ReviewStatus;
use App\Enums\UploadWorkflow;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\ReceivingUploadReprocessor;
use App\Features\Receiving\Services\ReviewLinkService;
use App\Features\Receiving\Services\UploadNotificationSender;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UploadLogController extends Controller
{
    public function index(Request $request, UploadSerialNumber $serials, PurchaseOrderDataNormalizer $normalizer): Response
    {
        $purchaseOrderView = $request->routeIs('admin.purchase-orders.index');
        $purchaseOrderUploadType = null;
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'review_email_status' => ['nullable', Rule::enum(EmailStatus::class)],
            'ai_status' => ['nullable', Rule::in(['waiting', 'in_progress', 'completed', 'failed'])],
            'review_status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'upload_type_id' => ['nullable', 'integer', 'exists:upload_types,id'],
        ]);
        if ($purchaseOrderView) {
            $purchaseOrderUploadType = UploadType::query()
                ->where('workflow', UploadWorkflow::PurchaseOrder)
                ->firstOrFail(['id', 'workflow']);
            $validated['upload_type_id'] = (int) $purchaseOrderUploadType->getKey();
            unset($validated['review_email_status'], $validated['review_status']);
        }
        $search = trim((string) ($validated['search'] ?? ''));
        $canRetryOperations = $request->user()?->can('operations.retry') ?? false;
        $purchaseOrderUploadTypeId = $purchaseOrderView ? (int) $validated['upload_type_id'] : null;
        $resolvedPoUploadId = $purchaseOrderUploadType === null || $search === ''
            ? null
            : $this->resolvePurchaseOrderSerialId($purchaseOrderUploadType, $search, $serials);

        $uploads = ReceivingUpload::query()
            ->with([
                'uploadType:id,name,workflow',
                'uploader:id,name,email',
                ...($purchaseOrderView
                    ? [
                        'poExtractions:id,receiving_upload_id,arrival_status,po_date_value,po_number',
                        'poExtractions.activeDocumentLinks.aiExtraction:id,receiving_upload_id,document_type',
                        'poExtractions.activeDocumentLinks.aiExtraction.upload:id,upload_type_id,serial_number',
                        'poExtractions.activeDocumentLinks.aiExtraction.upload.uploadType:id,name,workflow',
                        'purchaseOrderItemArrivals:id,receiving_upload_id,arrival_date',
                    ]
                    : [
                        'extractions:id,receiving_upload_id,po_link_status,document_type',
                        'extractions.activePurchaseOrderLink.poExtraction:id,receiving_upload_id,po_number',
                        'poExtractions:id,receiving_upload_id,arrival_status',
                    ]),
            ])
            ->when(! $purchaseOrderView, fn (Builder $query) => $query->whereHas(
                'uploadType',
                fn (Builder $type) => $type->where('workflow', '!=', UploadWorkflow::PurchaseOrder)
            ))
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search, $resolvedPoUploadId))
            ->when(isset($validated['review_email_status']), fn (Builder $query) => $query->where('review_email_status', $validated['review_email_status']))
            ->when(isset($validated['ai_status']), fn (Builder $query) => $query->whereIn(
                'ai_status',
                match ($validated['ai_status']) {
                    'waiting' => [AiStatus::Pending],
                    'in_progress' => [AiStatus::Processing],
                    'completed' => [AiStatus::Extracted, AiStatus::ManualReview],
                    'failed' => [AiStatus::PartialFailed, AiStatus::Failed],
                    default => [],
                },
            ))
            ->when(isset($validated['review_status']), fn (Builder $query) => $query->where('review_status', $validated['review_status']))
            ->when(isset($validated['upload_type_id']), fn (Builder $query) => $query->where('upload_type_id', $validated['upload_type_id']))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $serialNumbers = $serials->numbersFor($uploads->getCollection());

        $uploads = $uploads->through(fn (ReceivingUpload $upload): array => [
            'id' => $upload->getKey(),
            'serial_number' => $serialNumbers[$upload->getKey()] ?? $upload->getKey(),
            'serial_prefix' => $serials->prefix($upload->uploadType),
            'upload_type' => $upload->uploadType->name,
            'uploader_email' => $upload->uploader_email,
            'created_at' => $upload->created_at->toISOString(),
            'r2_prefix' => $upload->r2_prefix,
            'file_count' => $upload->file_count,
            'review_email_status' => $upload->review_email_status->value,
            'ai_status' => $upload->ai_status->value,
            'review_status' => $upload->review_status->value,
            'purchase_order_status' => $purchaseOrderView
                ? $this->purchaseOrderArrivalSummary($upload)
                : $this->purchaseOrderLinkSummary($upload),
            'linked_receipts' => $purchaseOrderView
                ? $this->purchaseOrderLinkedReceipts($upload, $serials)
                : [],
            'po_link_details' => $purchaseOrderView
                ? null
                : $this->uploadPoLinkDetails($upload),
            'waiting_time' => $purchaseOrderView
                ? $this->uploadWaitingTime($upload, $normalizer)
                : null,
            'can_resend_receiving' => $canRetryOperations
                && $upload->uploadType->workflow->sendsNotifications()
                && $upload->email_status->value === 'failed',
            'can_resend_review' => $canRetryOperations
                && $upload->uploadType->workflow->requiresReview()
                && $upload->ai_status === AiStatus::Extracted
                && $upload->review_status !== ReviewStatus::Verified,
            'can_reprocess' => $canRetryOperations
                && ! in_array($upload->ai_status, [AiStatus::Pending, AiStatus::Processing], true),
        ]);

        return Inertia::render(
            $purchaseOrderView ? 'admin/purchase-orders/index' : 'admin/uploads/index',
            [
                'uploads' => $uploads,
                'filters' => [
                    'search' => $search,
                    'review_email_status' => (string) ($validated['review_email_status'] ?? ''),
                    'ai_status' => (string) ($validated['ai_status'] ?? ''),
                    'review_status' => (string) ($validated['review_status'] ?? ''),
                    'upload_type_id' => isset($validated['upload_type_id']) ? (string) $validated['upload_type_id'] : '',
                ],
                'uploadTypes' => fn () => UploadType::query()
                    ->when(! $purchaseOrderView, fn (Builder $query) => $query->where('workflow', '!=', UploadWorkflow::PurchaseOrder))
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'pageMode' => $purchaseOrderView ? 'purchase_orders' : 'all_uploads',
                'basePath' => $purchaseOrderView ? '/admin/purchase-orders' : '/admin/uploads',
            ]
        );
    }

    /**
     * Compute waiting time (days from PO date to arrival date) at the upload level.
     *
     * "Arrived" is determined by po_extractions.arrival_status — the same signal the
     * Arrival status column uses — because a PO is marked Arrived when an invoice/receipt
     * upload is successfully linked to it, which may happen before any item-level
     * purchase_order_item_arrivals records exist.
     *
     * - Arrived:     days from po_date to first arrival_date (green pill)
     * - Not arrived: days elapsed since po_date up to today (amber "X days waiting")
     *
     * @return array{days: int|null, arrived: bool}|null
     */
    private function uploadWaitingTime(ReceivingUpload $upload, PurchaseOrderDataNormalizer $normalizer): ?array
    {
        $poDate = $upload->poExtractions
            ->map(fn ($po) => $po->po_date_value)
            ->filter()
            ->sort()
            ->first();

        if ($poDate === null) {
            return null;
        }

        // Use the same arrival check as purchaseOrderArrivalSummary().
        $isArrived = $upload->poExtractions->contains(
            fn ($po): bool => $po->arrival_status === PurchaseOrderArrivalStatus::Arrived
        );

        return [
            'days' => $normalizer->waitingDays($poDate, $upload->created_at),
            'arrived' => $isArrived,
        ];
    }

    private function purchaseOrderArrivalSummary(ReceivingUpload $upload): string
    {
        if ($upload->poExtractions->isEmpty()) {
            return PurchaseOrderArrivalStatus::Pending->value;
        }

        if ($upload->poExtractions->contains(fn ($po): bool => $po->arrival_status === PurchaseOrderArrivalStatus::Arrived)) {
            return PurchaseOrderArrivalStatus::Arrived->value;
        }

        if ($upload->poExtractions->contains(fn ($po): bool => $po->arrival_status === PurchaseOrderArrivalStatus::MissingPoNumber)) {
            return PurchaseOrderArrivalStatus::MissingPoNumber->value;
        }

        return PurchaseOrderArrivalStatus::Pending->value;
    }

    private function purchaseOrderLinkSummary(ReceivingUpload $upload): string
    {
        if ($upload->extractions->isEmpty()) {
            return PurchaseOrderLinkStatus::NotApplicable->value;
        }

        $statuses = $upload->extractions->pluck('po_link_status');
        foreach ([
            PurchaseOrderLinkStatus::Linked,
            PurchaseOrderLinkStatus::PurchaseOrderAlreadyLinked,
            PurchaseOrderLinkStatus::ReadyToLink,
            PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
            PurchaseOrderLinkStatus::MissingPoNumber,
        ] as $status) {
            if ($statuses->contains($status)) {
                return $status->value;
            }
        }

        return PurchaseOrderLinkStatus::NotApplicable->value;
    }

    /**
     * @return array<int, array{id: int, serial_number: int, serial_prefix: string, upload_type: string, document_type: string|null, linked_at: string}>
     */
    private function purchaseOrderLinkedReceipts(ReceivingUpload $upload, UploadSerialNumber $serials): array
    {
        $receipts = [];
        $seenIds = [];

        foreach ($upload->poExtractions as $po) {
            foreach ($po->activeDocumentLinks as $link) {
                $aiUpload = $link->aiExtraction->upload;
                $uploadId = (int) $aiUpload->getKey();
                if (isset($seenIds[$uploadId])) {
                    continue;
                }

                $seenIds[$uploadId] = true;
                $receipts[] = [
                    'id' => $uploadId,
                    'serial_number' => $serials->number($aiUpload),
                    'serial_prefix' => $serials->prefix($aiUpload->uploadType),
                    'upload_type' => $aiUpload->uploadType->name,
                    'document_type' => $link->aiExtraction->document_type,
                    'linked_at' => $link->created_at->toISOString(),
                ];
            }
        }

        return $receipts;
    }

    /**
     * @return array{status: string, total_invoices: int, linked_invoices: int, po_numbers: string[]}
     */
    private function uploadPoLinkDetails(ReceivingUpload $upload): array
    {
        $invoices = $upload->extractions->filter(
            fn ($e) => in_array($e->po_link_status, [
                PurchaseOrderLinkStatus::Linked,
                PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
                PurchaseOrderLinkStatus::MissingPoNumber,
                PurchaseOrderLinkStatus::ReadyToLink,
                PurchaseOrderLinkStatus::PurchaseOrderAlreadyLinked,
            ], true)
        );

        $linkedCount = $invoices->filter(
            fn ($e) => $e->po_link_status === PurchaseOrderLinkStatus::Linked
        )->count();

        /** @var string[] $poNumbers */
        $poNumbers = $upload->extractions
            ->map(fn ($e) => $e->activePurchaseOrderLink?->poExtraction?->po_number)
            ->filter(fn ($po): bool => is_string($po) && $po !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'status' => $this->purchaseOrderLinkSummary($upload),
            'total_invoices' => $invoices->count(),
            'linked_invoices' => $linkedCount,
            'po_numbers' => $poNumbers,
        ];
    }

    public function resendReceiving(Request $request, ReceivingUpload $upload, UploadNotificationSender $notifications): RedirectResponse
    {
        $this->authorize('resendNotification', $upload);

        return $notifications->send($upload, $request->user(), 'admin_upload_notification_resent')
            ? back()->with('status', 'Receiving email notification was resent successfully.')
            : back()->withErrors(['email' => 'Receiving email notification could not be resent.']);
    }

    public function resendReview(Request $request, ReceivingUpload $upload, ReviewLinkService $links, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('resendReviewNotification', $upload);

        if (! $links->issueAndSend($upload)) {
            return back()->withErrors(['review_email' => 'Review email notification could not be resent.']);
        }

        $activity->record('review', 'admin_review_email_resent', 'success', 'Administrator resent the review email notification.', $request->user(), $upload, $request);

        return back()->with('status', 'Review email notification was resent successfully.');
    }

    public function reprocess(Request $request, ReceivingUpload $upload, ReceivingUploadReprocessor $reprocessor): RedirectResponse
    {
        $this->authorize('reprocess', $upload);
        /** @var User $user */
        $user = $request->user();
        $reprocessor->queue($upload, $user, $request);

        return back()->with('status', 'All files under this serial number were queued for a new AI extraction.');
    }

    private function resolvePurchaseOrderSerialId(
        UploadType $uploadType,
        string $search,
        UploadSerialNumber $serials,
    ): ?int {
        if (preg_match('/^(?:posn|po)[\s-]*(\d+)$/i', $search, $matches) !== 1) {
            return null;
        }

        return $serials->resolve($uploadType, (int) $matches[1]);
    }

    private function applySearch(Builder $query, string $search, ?int $resolvedPoUploadId = null): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        $pattern = "%{$escaped}%";
        $serialNumber = preg_match('/^(?:sn[\s-]*)?(\d+)$/i', $search, $matches) === 1
            ? (int) $matches[1]
            : null;

        $query->where(function (Builder $query) use ($pattern, $serialNumber, $resolvedPoUploadId): void {
            if ($resolvedPoUploadId !== null) {
                $query->orWhere('receiving_uploads.id', $resolvedPoUploadId);
            } elseif ($serialNumber !== null) {
                $query->orWhere('receiving_uploads.serial_number', $serialNumber)
                    ->orWhere('receiving_uploads.id', $serialNumber);
            }

            $query
                ->orWhereRaw("LOWER(uploader_email) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('uploader', fn (Builder $uploader) => $uploader
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('uploadType', fn (Builder $type) => $type
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('files', fn (Builder $files) => $files
                    ->whereRaw("LOWER(original_file_name) LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('poExtractions', fn (Builder $extractions) => $extractions
                    ->whereRaw("LOWER(po_number) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(vendor_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(buyer_company) LIKE ? ESCAPE '!'", [$pattern])
                )
                ->orWhereHas('extractions', fn (Builder $extractions) => $extractions
                    ->where(function (Builder $extraction) use ($pattern): void {
                        $extraction
                            ->whereRaw("LOWER(document_type) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(CAST(raw_extracted_json AS TEXT)) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(CAST(corrected_json AS TEXT)) LIKE ? ESCAPE '!'", [$pattern]);
                    }));
        });
    }
}
