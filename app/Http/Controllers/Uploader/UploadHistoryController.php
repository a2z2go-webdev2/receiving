<?php

namespace App\Http\Controllers\Uploader;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Services\InvoiceReviewValidator;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Receiving\UploadRecordController;
use App\Models\AiExtraction;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UploadHistoryController extends Controller
{
    public function index(Request $request, UploadSerialNumber $serials): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'review_email_status' => ['nullable', Rule::enum(EmailStatus::class)],
            'ai_status' => ['nullable', Rule::in(['waiting', 'in_progress', 'completed', 'failed'])],
            'review_status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'upload_type_id' => ['nullable', 'integer'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        // All upload types this user has ever uploaded to (even if currently unassigned or inactive),
        // plus any upload types they currently have access to.
        $accessibleTypeIds = $user->uploadTypes()->pluck('upload_types.id');
        $uploadedTypeIds = ReceivingUpload::query()
            ->where('uploader_user_id', $user->getKey())
            ->where('processing_status', '!=', 'staging')
            ->select('upload_type_id')
            ->distinct()
            ->pluck('upload_type_id');

        $allTypeIds = $accessibleTypeIds->merge($uploadedTypeIds)->unique();

        $uploadTypes = UploadType::query()
            ->whereIn('id', $allTypeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $query = ReceivingUpload::query()
            ->where('uploader_user_id', $user->getKey())
            ->where('processing_status', '!=', 'staging')
            ->with([
                'uploadType:id,name,workflow',
                'uploader:id,name,email',
                'extractions:id,receiving_upload_id,po_link_status,document_type',
                'extractions.activePurchaseOrderLink.poExtraction:id,receiving_upload_id,po_number',
                'poExtractions:id,receiving_upload_id,arrival_status',
            ])
            ->when($search !== '', fn (Builder $q) => $this->applySearch($q, $search))
            ->when(isset($validated['upload_type_id']), fn (Builder $q) => $q->where('upload_type_id', $validated['upload_type_id']))
            ->when(isset($validated['review_email_status']), fn (Builder $q) => $q->where('review_email_status', $validated['review_email_status']))
            ->when(isset($validated['ai_status']), fn (Builder $q) => $q->whereIn(
                'ai_status',
                match ($validated['ai_status']) {
                    'waiting' => [AiStatus::Pending],
                    'in_progress' => [AiStatus::Processing],
                    'completed' => [AiStatus::Extracted, AiStatus::ManualReview],
                    'failed' => [AiStatus::PartialFailed, AiStatus::Failed],
                    default => [],
                },
            ))
            ->when(isset($validated['review_status']), fn (Builder $q) => $q->where('review_status', $validated['review_status']))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $serialNumbers = $serials->numbersFor($query->getCollection());

        $uploads = $query->through(fn (ReceivingUpload $upload): array => [
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
            'purchase_order_status' => $this->purchaseOrderLinkSummary($upload),
            'po_link_details' => $this->uploadPoLinkDetails($upload),
            'detail_url' => route('uploader.uploads.show', $upload),
        ]);

        return Inertia::render('uploader/uploads/index', [
            'uploads' => $uploads,
            'filters' => [
                'search' => $search,
                'review_email_status' => (string) ($validated['review_email_status'] ?? ''),
                'ai_status' => (string) ($validated['ai_status'] ?? ''),
                'review_status' => (string) ($validated['review_status'] ?? ''),
                'upload_type_id' => isset($validated['upload_type_id']) ? (string) $validated['upload_type_id'] : '',
            ],
            'uploadTypes' => $uploadTypes,
            'basePath' => route('uploader.uploads'),
        ]);
    }

    public function show(
        Request $request,
        ReceivingUpload $upload,
        UploadRecordController $recordController,
        ReceivingSettings $settings,
        InvoiceReviewValidator $validator,
        UploadSerialNumber $serials,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($upload->uploader_user_id === $user->getKey(), 403);

        return $recordController->showUploader($request, $upload, $settings, $validator, $serials);
    }

    public function fileUrl(
        Request $request,
        UploadedFile $file,
        ReceivingSettings $settings,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $file->loadMissing('upload');
        abort_unless($file->upload->uploader_user_id === $user->getKey(), 403);

        $key = $file->resolvedR2ObjectKey();
        abort_if($key === null, 404);

        try {
            $url = Storage::disk((string) config('receiving.disk'))->temporaryUrl(
                $key,
                now()->addMinutes((int) $settings->get('signed_url_expiration_minutes')),
            );
        } catch (Throwable) {
            $url = route('uploader.files.preview', $file);
        }

        return response()->json(['url' => $url]);
    }

    public function filePreview(Request $request, UploadedFile $file): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $file->loadMissing('upload');
        abort_unless($file->upload->uploader_user_id === $user->getKey(), 403);
        $key = $file->resolvedR2ObjectKey();
        abort_if($key === null, 404);

        return Storage::disk((string) config('receiving.disk'))->response(
            $key,
            $file->sanitized_file_name,
            ['Content-Type' => $file->content_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function applySearch(Builder $query, string $search): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        $pattern = "%{$escaped}%";
        $serialNumber = preg_match('/^(?:sn[\s-]*)?(\d+)$/i', $search, $matches) === 1
            ? (int) $matches[1]
            : null;

        $query->where(function (Builder $q) use ($pattern, $serialNumber): void {
            if ($serialNumber !== null) {
                $q->orWhere('receiving_uploads.serial_number', $serialNumber)
                    ->orWhere('receiving_uploads.id', $serialNumber);
            }

            $q
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

    /**
     * @param  Collection<int, AiExtraction>|null  $targetExtractions
     */
    private function purchaseOrderLinkSummary(ReceivingUpload $upload, ?Collection $targetExtractions = null): string
    {
        $extractions = $targetExtractions ?? $this->resolvePrimaryPoLinkExtractions($upload);

        if ($extractions->isEmpty()) {
            return PurchaseOrderLinkStatus::NotApplicable->value;
        }

        $statuses = $extractions->pluck('po_link_status');
        foreach ([
            PurchaseOrderLinkStatus::Linked,
            PurchaseOrderLinkStatus::PurchaseOrderAlreadyLinked,
            PurchaseOrderLinkStatus::ReadyToLink,
            PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
            PurchaseOrderLinkStatus::MissingPoNumber,
            PurchaseOrderLinkStatus::Conflict,
            PurchaseOrderLinkStatus::Ambiguous,
        ] as $status) {
            if ($statuses->contains($status)) {
                return $status->value;
            }
        }

        return PurchaseOrderLinkStatus::NotApplicable->value;
    }

    private function isInvoiceDocumentType(?string $documentType): bool
    {
        if ($documentType === null) {
            return false;
        }

        $lower = Str::lower(trim($documentType));
        if ($lower === '') {
            return false;
        }

        if (
            Str::contains($lower, ['purchase order', 'purchase_order', 'purchase-order'])
            || $lower === 'po'
            || str_starts_with($lower, 'po ')
            || str_ends_with($lower, ' po')
        ) {
            return false;
        }

        return Str::contains($lower, ['invoice', 'billing'])
            || in_array($lower, ['si', 'ci', 'bill'], true)
            || str_starts_with($lower, 'si ')
            || str_ends_with($lower, ' si');
    }

    private function isDeliveryReceiptDocumentType(?string $documentType): bool
    {
        if ($documentType === null) {
            return false;
        }

        $lower = Str::lower(trim($documentType));
        if ($lower === '') {
            return false;
        }

        if (
            Str::contains($lower, ['purchase order', 'purchase_order', 'purchase-order'])
            || $lower === 'po'
            || str_starts_with($lower, 'po ')
            || str_ends_with($lower, ' po')
        ) {
            return false;
        }

        return Str::contains($lower, ['delivery', 'receipt', 'waybill', 'slip'])
            || in_array($lower, ['dr'], true)
            || str_starts_with($lower, 'dr ')
            || str_ends_with($lower, ' dr');
    }

    /**
     * @return Collection<int, AiExtraction>
     */
    private function resolvePrimaryPoLinkExtractions(ReceivingUpload $upload): Collection
    {
        /** @var Collection<int, AiExtraction> $linkable */
        $linkable = $upload->extractions->filter(
            fn (AiExtraction $e): bool => in_array($e->po_link_status, [
                PurchaseOrderLinkStatus::Linked,
                PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
                PurchaseOrderLinkStatus::MissingPoNumber,
                PurchaseOrderLinkStatus::ReadyToLink,
                PurchaseOrderLinkStatus::PurchaseOrderAlreadyLinked,
                PurchaseOrderLinkStatus::Ambiguous,
                PurchaseOrderLinkStatus::Conflict,
            ], true)
        );

        if ($linkable->isEmpty()) {
            return collect();
        }

        $invoices = $linkable->filter(
            fn (AiExtraction $e): bool => $this->isInvoiceDocumentType($e->document_type)
        );

        if ($invoices->isNotEmpty()) {
            return $invoices;
        }

        $deliveryReceipts = $linkable->filter(
            fn (AiExtraction $e): bool => $this->isDeliveryReceiptDocumentType($e->document_type)
        );

        if ($deliveryReceipts->isNotEmpty()) {
            return $deliveryReceipts;
        }

        return $linkable;
    }

    /**
     * @return array{status: string, total_invoices: int, linked_invoices: int, po_numbers: string[]}
     */
    private function uploadPoLinkDetails(ReceivingUpload $upload): array
    {
        $primaryExtractions = $this->resolvePrimaryPoLinkExtractions($upload);

        $linkedCount = $primaryExtractions->filter(
            fn (AiExtraction $e): bool => $e->po_link_status === PurchaseOrderLinkStatus::Linked
        )->count();

        /** @var string[] $poNumbers */
        $poNumbers = $primaryExtractions
            ->map(fn (AiExtraction $e): ?string => $e->activePurchaseOrderLink?->poExtraction?->po_number)
            ->filter(fn (?string $po): bool => is_string($po) && $po !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($poNumbers)) {
            $poNumbers = $upload->extractions
                ->map(fn (AiExtraction $e): ?string => $e->activePurchaseOrderLink?->poExtraction?->po_number)
                ->filter(fn (?string $po): bool => is_string($po) && $po !== '')
                ->unique()
                ->values()
                ->all();
        }

        return [
            'status' => $this->purchaseOrderLinkSummary($upload, $primaryExtractions),
            'total_invoices' => $primaryExtractions->count(),
            'linked_invoices' => $linkedCount,
            'po_numbers' => $poNumbers,
        ];
    }
}
