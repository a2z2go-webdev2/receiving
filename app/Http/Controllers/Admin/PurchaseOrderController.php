<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiStatus;
use App\Enums\Permission;
use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderSourceStatus;
use App\Enums\UploadWorkflow;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncGoogleSheetRequest;
use App\Jobs\SyncPurchaseOrderSheet;
use App\Models\GoogleSheetConfig;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\PurchaseOrderItemArrival;
use App\Models\ReceivingUpload;
use App\Models\UploadType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(
        Request $request,
        UploadSerialNumber $serials,
        PurchaseOrderDataNormalizer $normalizer,
    ): Response {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'ai_status' => ['nullable', Rule::in(['waiting', 'in_progress', 'completed', 'failed'])],
            'source_status' => ['nullable', Rule::enum(PurchaseOrderSourceStatus::class)],
            'source_type' => ['nullable', Rule::in(['upload', 'google_sheet'])],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $canRetryOperations = $request->user()?->can(Permission::RetryOperations->value) ?? false;
        $sourceType = $validated['source_type'] ?? null;

        $poUploadType = UploadType::query()
            ->where('workflow', UploadWorkflow::PurchaseOrder)
            ->first();

        $resolvedPoUploadId = $poUploadType === null || $search === ''
            ? null
            : $this->resolvePurchaseOrderSerialId($poUploadType, $search, $serials);

        // 1. If only Google Sheet POs requested
        if ($sourceType === 'google_sheet') {
            $sheetQuery = PoExtraction::query()
                ->where('source_type', 'google_sheet')
                ->with([
                    'sheetSource',
                    'activeDocumentLinks.aiExtraction.upload.uploadType',
                    'purchaseOrderItemArrivals',
                ])
                ->when($search !== '', function (Builder $q) use ($search) {
                    $q->where(function (Builder $sub) use ($search) {
                        $sub->where('po_number', 'LIKE', "%{$search}%")
                            ->orWhere('vendor_name', 'LIKE', "%{$search}%")
                            ->orWhere('vendor_raw', 'LIKE', "%{$search}%")
                            ->orWhere('buyer_company', 'LIKE', "%{$search}%");
                    });
                })
                ->when(isset($validated['source_status']), fn (Builder $q) => $q->where('source_status', $validated['source_status']))
                ->orderByDesc('id');

            $paginatedSheets = $sheetQuery->paginate(25)->withQueryString();
            $items = $paginatedSheets->through(fn (PoExtraction $po) => $this->formatSheetPo($po, $serials, $normalizer));

            return $this->renderIndex($items, $search, $validated);
        }

        // 2. Query PO Uploads
        $uploadQuery = ReceivingUpload::query()
            ->with([
                'uploadType:id,name,workflow',
                'uploader:id,name,email',
                'poExtractions:id,receiving_upload_id,arrival_status,source_status,source_type,po_date_value,po_number,vendor_name',
                'poExtractions.activeDocumentLinks.aiExtraction:id,receiving_upload_id,document_type',
                'poExtractions.activeDocumentLinks.aiExtraction.upload:id,upload_type_id,serial_number',
                'poExtractions.activeDocumentLinks.aiExtraction.upload.uploadType:id,name,workflow',
                'purchaseOrderItemArrivals:id,receiving_upload_id,arrival_date',
            ])
            ->whereHas('uploadType', fn (Builder $type) => $type->where('workflow', UploadWorkflow::PurchaseOrder))
            ->when($search !== '', fn (Builder $query) => $this->applySearch($query, $search, $resolvedPoUploadId))
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
            ->latest('id');

        // Check if there are also Google Sheet POs to include (when not filtered to upload only)
        $hasSheetPos = $sourceType !== 'upload' && PoExtraction::query()->where('source_type', 'google_sheet')->exists();

        if (! $hasSheetPos) {
            $uploads = $uploadQuery->paginate(25)->withQueryString();
            $serialNumbers = $serials->numbersFor($uploads->getCollection());

            $items = $uploads->through(fn (ReceivingUpload $upload) => $this->formatUploadPo($upload, $serialNumbers, $serials, $normalizer, $canRetryOperations));

            return $this->renderIndex($items, $search, $validated);
        }

        // When both exist and no source_type filter was set:
        // Combine uploads and sheet POs
        $sheetQuery = PoExtraction::query()
            ->where('source_type', 'google_sheet')
            ->with([
                'sheetSource',
                'activeDocumentLinks.aiExtraction.upload.uploadType',
                'purchaseOrderItemArrivals',
            ])
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('po_number', 'LIKE', "%{$search}%")
                        ->orWhere('vendor_name', 'LIKE', "%{$search}%")
                        ->orWhere('vendor_raw', 'LIKE', "%{$search}%")
                        ->orWhere('buyer_company', 'LIKE', "%{$search}%");
                });
            })
            ->when(isset($validated['source_status']), fn (Builder $q) => $q->where('source_status', $validated['source_status']))
            ->orderByDesc('id');

        $uploads = $uploadQuery->get();
        $sheetPos = $sheetQuery->get();

        $serialNumbers = $serials->numbersFor($uploads);

        $formattedUploads = $uploads->map(fn (ReceivingUpload $u) => $this->formatUploadPo($u, $serialNumbers, $serials, $normalizer, $canRetryOperations));
        $formattedSheets = $sheetPos->map(fn (PoExtraction $po) => $this->formatSheetPo($po, $serials, $normalizer));

        $allCombined = $formattedSheets->concat($formattedUploads)->sortByDesc('created_at')->values();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 25;
        $sliced = $allCombined->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $sliced,
            $allCombined->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->renderIndex($paginator, $search, $validated);
    }

    public function show(
        Request $request,
        PoExtraction $purchaseOrder,
        UploadSerialNumber $serials,
        PurchaseOrderDataNormalizer $normalizer,
    ): Response {
        $purchaseOrder->load([
            'upload.uploadType',
            'sheetSource',
            'items.schedule',
            'activeDocumentLinks.aiExtraction.upload.uploadType',
            'activeDocumentLinks.arrivals.stockLot',
            'purchaseOrderItemArrivals.stockLot',
        ]);

        $linkedUploads = $purchaseOrder->activeDocumentLinks
            ->map(fn (PurchaseOrderDocumentLink $l): ReceivingUpload => $l->aiExtraction->upload)
            ->filter()
            ->values();

        $serialNumbers = $serials->numbersFor(new EloquentCollection($linkedUploads->all()));

        $waitingTime = $this->computeWaitingTime($purchaseOrder, $normalizer);
        $sheetSource = $purchaseOrder->sheetSource;

        return Inertia::render('admin/purchase-orders/show', [
            'purchaseOrder' => [
                'id' => $purchaseOrder->getKey(),
                'po_number' => $purchaseOrder->po_number,
                'po_reference' => $purchaseOrder->po_reference,
                'po_date' => $purchaseOrder->po_date,
                'po_date_value' => $purchaseOrder->po_date_value?->toDateString(),
                'buyer_company' => $purchaseOrder->buyer_company,
                'buyer_address' => $purchaseOrder->buyer_address,
                'buyer_contact_numbers' => $purchaseOrder->buyer_contact_numbers,
                'vendor_name' => $purchaseOrder->vendor_name,
                'vendor_raw' => $purchaseOrder->vendor_name,
                'contact_person' => $purchaseOrder->contact_person,
                'vendor_email' => $purchaseOrder->vendor_email,
                'vendor_mobile' => $purchaseOrder->vendor_mobile,
                'vendor_address' => $purchaseOrder->vendor_address,
                'payment_terms' => $purchaseOrder->payment_terms,
                'subtotal' => $purchaseOrder->subtotal,
                'vat' => $purchaseOrder->vat,
                'total_amount' => $purchaseOrder->total_amount,
                'arrival_status' => $purchaseOrder->arrival_status->value,
                'source_type' => $purchaseOrder->source_type ?? 'upload',
                'source_status' => $purchaseOrder->source_status ?? ($purchaseOrder->source_type === 'google_sheet' ? 'confirmed' : 'active'),
                'sync_key' => $purchaseOrder->source_row_hash,
                'snapshot_timestamp' => $purchaseOrder->synced_at?->toISOString(),
                'notes' => $purchaseOrder->notes,
                'financial_adjustments' => [],
                'sheet_source' => $sheetSource instanceof GoogleSheetConfig ? [
                    'id' => $sheetSource->getKey(),
                    'name' => $sheetSource->name,
                    'slug' => $sheetSource->slug,
                    'last_synced_at' => $sheetSource->last_synced_at?->toISOString(),
                ] : null,
                'upload' => $purchaseOrder->upload ? [
                    'id' => $purchaseOrder->upload->getKey(),
                    'serial_number' => $serials->number($purchaseOrder->upload),
                    'serial_prefix' => $serials->prefix($purchaseOrder->upload->uploadType),
                    'created_at' => $purchaseOrder->upload->created_at->toISOString(),
                ] : null,
                'items' => $purchaseOrder->items->map(function (PoExtractionItem $item): array {
                    $firstFulfillment = $item->fulfillments->first();
                    $matchedSchedule = $firstFulfillment?->schedule;

                    return [
                        'id' => $item->getKey(),
                        'sort_order' => $item->sort_order,
                        'item_code' => $item->item_code,
                        'product_description' => $item->product_description,
                        'package' => $item->package,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'unit_price' => $item->unit_price,
                        'line_total' => $item->line_total,
                        'is_financial_adjustment' => (bool) $item->is_financial_adjustment,
                        'is_catalog_matched' => $matchedSchedule !== null,
                        'matched_schedule' => $matchedSchedule ? [
                            'id' => $matchedSchedule->getKey(),
                            'sku_number' => $matchedSchedule->sku_number,
                            'description' => $matchedSchedule->description,
                            'unit' => $matchedSchedule->unit,
                        ] : null,
                    ];
                })->all(),
                'linked_receipts' => $purchaseOrder->activeDocumentLinks->map(function (PurchaseOrderDocumentLink $link) use ($serialNumbers, $serials): array {
                    $ext = $link->aiExtraction;
                    $up = $ext->upload;

                    return [
                        'id' => $link->getKey(),
                        'upload_id' => $up->getKey(),
                        'serial_number' => $serialNumbers[$up->getKey()] ?? $up->getKey(),
                        'serial_prefix' => $serials->prefix($up->uploadType),
                        'upload_type' => $up->uploadType->name,
                        'document_type' => $ext->document_type,
                        'source' => $link->source->value,
                        'linked_at' => $link->created_at->toISOString(),
                        'arrivals' => $link->arrivals->map(fn (PurchaseOrderItemArrival $a): array => [
                            'id' => $a->getKey(),
                            'item_code' => $a->item_code,
                            'product_description' => $a->item_description,
                            'arrived_quantity' => (float) $a->arrived_quantity,
                            'unit' => $a->unit,
                            'arrival_date' => $a->arrival_date?->toDateString(),
                            'posting_status' => $a->posting_status,
                            'stock_lot' => $a->stockLot ? [
                                'id' => $a->stockLot->getKey(),
                                'lot_number' => $a->stockLot->lot_number,
                                'quantity_received' => (float) $a->stockLot->quantity_received,
                                'posting_provenance' => $a->stockLot->posting_provenance,
                            ] : null,
                        ])->all(),
                    ];
                })->all(),
                'waiting_time' => $waitingTime,
            ],
        ]);
    }

    public function sync(SyncGoogleSheetRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $target = $validated['sheet_config_id'] ?? $validated['slug'] ?? 'po';
        $range = $validated['range'] ?? null;
        $mode = $validated['mode'] ?? 'apply';

        SyncPurchaseOrderSheet::dispatch($target, $range, $mode);

        return back()->with('status', 'Purchase order synchronization has been queued.');
    }

    private function renderIndex(mixed $uploads, string $search, array $validated): Response
    {
        return Inertia::render('admin/purchase-orders/index', [
            'uploads' => $uploads,
            'filters' => [
                'search' => $search,
                'ai_status' => (string) ($validated['ai_status'] ?? ''),
                'source_status' => (string) ($validated['source_status'] ?? ''),
                'source_type' => (string) ($validated['source_type'] ?? ''),
            ],
            'uploadTypes' => fn () => UploadType::query()
                ->where('workflow', UploadWorkflow::PurchaseOrder)
                ->orderBy('name')
                ->get(['id', 'name']),
            'sheetSources' => fn () => GoogleSheetConfig::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'tab_name', 'spreadsheet_id', 'transition_mode', 'last_synced_at']),
            'pageMode' => 'purchase_orders',
            'basePath' => '/admin/purchase-orders',
        ]);
    }

    private function formatUploadPo(
        ReceivingUpload $upload,
        array $serialNumbers,
        UploadSerialNumber $serials,
        PurchaseOrderDataNormalizer $normalizer,
        bool $canRetryOperations,
    ): array {
        $po = $upload->poExtractions->first();

        return [
            'id' => $upload->getKey(),
            'po_id' => $po?->getKey(),
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
            'purchase_order_status' => $this->purchaseOrderArrivalSummary($upload),
            'source_type' => 'upload',
            'source_status' => $po->source_status ?? 'active',
            'linked_receipts' => $this->purchaseOrderLinkedReceipts($upload, $serials),
            'po_link_details' => null,
            'waiting_time' => $this->uploadWaitingTime($upload, $normalizer),
            'detail_url' => $po ? route('admin.purchase-orders.show', $po) : route('admin.uploads.show', $upload),
            'can_resend_receiving' => false,
            'can_resend_review' => false,
            'can_reprocess' => $canRetryOperations && ! in_array($upload->ai_status, [AiStatus::Pending, AiStatus::Processing], true),
            'can_delete' => $canRetryOperations,
        ];
    }

    private function formatSheetPo(
        PoExtraction $po,
        UploadSerialNumber $serials,
        PurchaseOrderDataNormalizer $normalizer,
    ): array {
        $linkedReceipts = $po->activeDocumentLinks->map(function (PurchaseOrderDocumentLink $link) use ($serials): array {
            $ext = $link->aiExtraction;
            $up = $ext->upload;

            return [
                'id' => $up->getKey(),
                'serial_number' => $serials->number($up),
                'serial_prefix' => $serials->prefix($up->uploadType),
                'upload_type' => $up->uploadType->name,
                'document_type' => $ext->document_type,
                'linked_at' => $link->created_at->toISOString(),
            ];
        })->all();

        $sheetSource = $po->sheetSource;

        return [
            'id' => $po->getKey(),
            'po_id' => $po->getKey(),
            'serial_number' => $po->po_number,
            'serial_prefix' => 'PO',
            'upload_type' => 'Google Sheet ('.($sheetSource instanceof GoogleSheetConfig ? $sheetSource->name : 'Sync').')',
            'uploader_email' => 'Google Sheet Sync',
            'created_at' => ($po->synced_at?->toISOString() ?? $po->created_at->toISOString()),
            'r2_prefix' => '',
            'file_count' => 0,
            'review_email_status' => 'none',
            'ai_status' => 'completed',
            'review_status' => 'verified',
            'purchase_order_status' => $po->arrival_status->value,
            'source_type' => 'google_sheet',
            'source_status' => $po->source_status ?? 'confirmed',
            'linked_receipts' => $linkedReceipts,
            'po_link_details' => null,
            'waiting_time' => $this->computeWaitingTime($po, $normalizer),
            'detail_url' => route('admin.purchase-orders.show', $po),
            'can_resend_receiving' => false,
            'can_resend_review' => false,
            'can_reprocess' => false,
            'can_delete' => false,
        ];
    }

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

    private function computeWaitingTime(PoExtraction $po, PurchaseOrderDataNormalizer $normalizer): array
    {
        $isArrived = $po->arrival_status === PurchaseOrderArrivalStatus::Arrived;
        if (! $isArrived) {
            return ['days' => null, 'arrived' => false];
        }

        $earliestArrivalDate = $po->purchaseOrderItemArrivals
            ->pluck('arrival_date')
            ->filter()
            ->sort()
            ->first();

        if ($earliestArrivalDate === null) {
            $earliestArrivalDate = $po->activeDocumentLinks
                ->flatMap(fn ($l) => $l->arrivals->pluck('arrival_date'))
                ->filter()
                ->sort()
                ->first();
        }

        $poDateObj = $po->po_date_value ?? ($po->po_date ? CarbonImmutable::parse($po->po_date) : null);
        $arrivalDateObj = $earliestArrivalDate instanceof CarbonImmutable
            ? $earliestArrivalDate
            : (is_string($earliestArrivalDate) ? CarbonImmutable::parse($earliestArrivalDate) : null);

        $days = ($poDateObj && $arrivalDateObj)
            ? $normalizer->waitingDays($poDateObj, $arrivalDateObj)
            : null;

        return ['days' => $days, 'arrived' => true];
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
