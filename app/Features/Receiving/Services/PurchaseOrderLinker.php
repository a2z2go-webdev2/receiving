<?php

namespace App\Features\Receiving\Services;

use App\Enums\PurchaseOrderArrivalStatus;
use App\Enums\PurchaseOrderLinkSource;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\UploadWorkflow;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\PurchaseOrderItemArrival;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderLinker
{
    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    public function syncExtraction(AiExtraction $extraction): PurchaseOrderLinkStatus
    {
        return DB::transaction(function () use ($extraction): PurchaseOrderLinkStatus {
            /** @var AiExtraction $locked */
            $locked = AiExtraction::query()
                ->with(['upload.uploadType', 'activePurchaseOrderLink.poExtraction'])
                ->lockForUpdate()
                ->findOrFail($extraction->getKey());

            return $this->syncLockedExtraction($locked, true);
        });
    }

    public function syncPoExtraction(PoExtraction $poExtraction): void
    {
        $normalizedPoNumber = DB::transaction(function () use ($poExtraction): ?string {
            /** @var PoExtraction $locked */
            $locked = PoExtraction::query()
                ->with('activeDocumentLinks')
                ->lockForUpdate()
                ->findOrFail($poExtraction->getKey());

            foreach ($locked->activeDocumentLinks as $link) {
                $this->syncArrivals($link);
            }
            $this->refreshPoArrivalStatus($locked);

            return $locked->po_number_normalized;
        });

        if ($normalizedPoNumber === null) {
            return;
        }

        AiExtraction::query()
            ->where('po_number_normalized', $normalizedPoNumber)
            ->whereDoesntHave('activePurchaseOrderLink')
            ->orderBy('id')
            ->chunkById(100, function (Collection $extractions): void {
                foreach ($extractions as $extraction) {
                    if ($extraction instanceof AiExtraction) {
                        $this->syncExtraction($extraction);
                    }
                }
            });
    }

    /** @return array{processed: int, linked: int} */
    public function resyncAll(): array
    {
        $processed = 0;
        $linked = 0;

        AiExtraction::query()
            ->whereHas('upload.uploadType', fn ($query) => $query
                ->where('workflow', UploadWorkflow::Standard->value))
            ->orderBy('id')
            ->chunkById(100, function (Collection $extractions) use (&$processed, &$linked): void {
                foreach ($extractions as $extraction) {
                    if (! $extraction instanceof AiExtraction) {
                        continue;
                    }

                    $status = $this->syncExtraction($extraction);
                    $processed++;
                    if ($status === PurchaseOrderLinkStatus::Linked) {
                        $linked++;
                    }
                }
            });

        return ['processed' => $processed, 'linked' => $linked];
    }

    public function link(
        AiExtraction $extraction,
        PoExtraction $poExtraction,
        ?User $actor,
        PurchaseOrderLinkSource $source = PurchaseOrderLinkSource::Manual,
    ): PurchaseOrderDocumentLink {
        return DB::transaction(function () use ($extraction, $poExtraction, $actor, $source): PurchaseOrderDocumentLink {
            /** @var AiExtraction $lockedExtraction */
            $lockedExtraction = AiExtraction::query()
                ->with('upload.uploadType')
                ->lockForUpdate()
                ->findOrFail($extraction->getKey());
            /** @var PoExtraction $lockedPo */
            $lockedPo = PoExtraction::query()
                ->lockForUpdate()
                ->findOrFail($poExtraction->getKey());

            return $this->createLink($lockedExtraction, $lockedPo, $source, $actor);
        });
    }

    public function unlink(PurchaseOrderDocumentLink $link, ?User $actor = null): void
    {
        DB::transaction(function () use ($link, $actor): void {
            /** @var PurchaseOrderDocumentLink $locked */
            $locked = PurchaseOrderDocumentLink::query()
                ->with(['aiExtraction.upload.uploadType', 'poExtraction'])
                ->lockForUpdate()
                ->findOrFail($link->getKey());

            if ($locked->unlinked_at !== null) {
                return;
            }

            $locked->forceFill([
                'unlinked_at' => now(),
                'unlinked_by_user_id' => $actor?->getKey(),
            ])->save();
            $locked->arrivals()->delete();

            $this->refreshPoArrivalStatus($locked->poExtraction);
            $this->syncLockedExtraction($locked->aiExtraction, false);
        });
    }

    private function syncLockedExtraction(AiExtraction $extraction, bool $autoLink): PurchaseOrderLinkStatus
    {
        $extraction->loadMissing(['upload.uploadType', 'activePurchaseOrderLink.poExtraction']);

        if (! $this->isStandardInvoiceOrReceipt($extraction)) {
            $this->unlinkActiveExtractionLink($extraction);

            return $this->setExtractionStatus($extraction, PurchaseOrderLinkStatus::NotApplicable);
        }

        $activeLink = $extraction->activePurchaseOrderLink;
        if ($activeLink !== null) {
            $po = $activeLink->poExtraction;
            if ($this->linkStillMatches($extraction, $po)) {
                $this->fillMissingFieldsFromPo($extraction, $po);
                $this->refreshPoArrivalStatus($po);
                $this->syncArrivals($activeLink);

                return $this->setExtractionStatus($extraction, PurchaseOrderLinkStatus::Linked);
            }

            $this->unlink($activeLink);
            $extraction->load('upload.uploadType');
        }

        $poNumber = $this->normalizer->poNumber($this->dataFor($extraction) ?? []);
        $normalizedPoNumber = $this->normalizer->normalizeIdentifier($poNumber);
        if ($normalizedPoNumber === null) {
            return $this->setExtractionStatus($extraction, PurchaseOrderLinkStatus::MissingPoNumber);
        }

        $matchingPo = PoExtraction::query()
            ->where('po_number_normalized', $normalizedPoNumber)
            ->orderByDesc('po_date_value')
            ->orderByDesc('id')
            ->get();

        if ($matchingPo->isEmpty()) {
            return $this->setExtractionStatus($extraction, PurchaseOrderLinkStatus::AwaitingPurchaseOrder);
        }

        /** @var PoExtraction $matchingPurchaseOrder */
        $matchingPurchaseOrder = $matchingPo->first();

        if (! $autoLink) {
            return $this->setExtractionStatus($extraction, PurchaseOrderLinkStatus::ReadyToLink);
        }

        $this->createLink($extraction, $matchingPurchaseOrder, PurchaseOrderLinkSource::Automatic, null);

        return PurchaseOrderLinkStatus::Linked;
    }

    private function createLink(
        AiExtraction $extraction,
        PoExtraction $poExtraction,
        PurchaseOrderLinkSource $source,
        ?User $actor,
    ): PurchaseOrderDocumentLink {
        if (! $this->isStandardInvoiceOrReceipt($extraction)) {
            throw ValidationException::withMessages([
                'po_extraction_id' => 'Only invoice or receipt extractions can be linked to a purchase order.',
            ]);
        }

        if ($this->extractionHasActiveLink($extraction)) {
            throw ValidationException::withMessages([
                'po_extraction_id' => 'This invoice or receipt is already linked. Unlink it first.',
            ]);
        }

        $extractionPoNumber = $this->normalizer->poNumber($this->dataFor($extraction) ?? []);
        $extractionPoNumberNormalized = $this->normalizer->normalizeIdentifier($extractionPoNumber);
        if ($extractionPoNumberNormalized !== null
            && $extractionPoNumberNormalized !== $poExtraction->po_number_normalized) {
            throw ValidationException::withMessages([
                'po_extraction_id' => 'The selected purchase order number does not match this invoice or receipt.',
            ]);
        }

        $link = PurchaseOrderDocumentLink::query()->create([
            'po_extraction_id' => $poExtraction->getKey(),
            'ai_extraction_id' => $extraction->getKey(),
            'source' => $source,
            'linked_by_user_id' => $actor?->getKey(),
        ]);

        $this->fillMissingFieldsFromPo($extraction, $poExtraction);
        $this->setExtractionStatus($extraction, PurchaseOrderLinkStatus::Linked);
        $poExtraction->forceFill(['arrival_status' => PurchaseOrderArrivalStatus::Arrived])->save();
        $this->syncArrivals($link);

        return $link;
    }

    private function unlinkActiveExtractionLink(AiExtraction $extraction): void
    {
        $link = PurchaseOrderDocumentLink::query()
            ->active()
            ->where('ai_extraction_id', $extraction->getKey())
            ->first();

        if ($link !== null) {
            $this->unlink($link);
        }
    }

    private function refreshPoArrivalStatus(PoExtraction $poExtraction): PurchaseOrderArrivalStatus
    {
        $status = $this->poHasActiveLink($poExtraction)
            ? PurchaseOrderArrivalStatus::Arrived
            : ($poExtraction->po_number_normalized === null
                ? PurchaseOrderArrivalStatus::MissingPoNumber
                : PurchaseOrderArrivalStatus::Pending);

        $poExtraction->forceFill(['arrival_status' => $status])->save();

        return $status;
    }

    private function setExtractionStatus(
        AiExtraction $extraction,
        PurchaseOrderLinkStatus $status,
    ): PurchaseOrderLinkStatus {
        $extraction->forceFill(['po_link_status' => $status])->save();

        return $status;
    }

    private function fillMissingFieldsFromPo(AiExtraction $extraction, PoExtraction $poExtraction): void
    {
        $raw = $this->withMissingPoFields($extraction->raw_extracted_json, $poExtraction);
        $corrected = $this->withMissingPoFields($extraction->corrected_json, $poExtraction);

        $extraction->forceFill([
            'raw_extracted_json' => $raw,
            'corrected_json' => $corrected,
            'po_date_filled_from_po_extraction_id' => $poExtraction->getKey(),
        ])->save();
    }

    /** @param array<string, mixed>|null $data @return array<string, mixed>|null */
    private function withMissingPoFields(?array $data, PoExtraction $poExtraction): ?array
    {
        if ($data === null || ! $this->normalizer->isInvoiceOrReceipt($data)) {
            return $data;
        }

        if ($this->normalizer->poNumber($data) === null
            && $this->normalizer->hasMeaningfulValue($poExtraction->po_number)) {
            $data = $this->normalizer->withFieldValue($data, 'PO Number', (string) $poExtraction->po_number);
        }

        if ($this->normalizer->poDate($data) === null
            && $this->normalizer->hasMeaningfulValue($poExtraction->po_date)) {
            $data = $this->normalizer->withFieldValue($data, 'PO Date', (string) $poExtraction->po_date);
        }

        return $data;
    }

    private function syncArrivals(PurchaseOrderDocumentLink $link): void
    {
        $link->loadMissing([
            'poExtraction.items.fulfillments.schedule',
            'aiExtraction.upload.uploadType',
        ]);
        $link->arrivals()->delete();

        $data = $this->dataFor($link->aiExtraction);
        $items = $data['items'] ?? null;
        if (! is_array($items)) {
            return;
        }

        $poDate = $link->poExtraction->po_date_value
            ?? $this->normalizer->parseDate($link->poExtraction->po_date);
        $poWeek = $poDate === null ? null : $this->normalizer->weekOfMonth($poDate);
        $arrivalDate = $link->aiExtraction->upload->upload_completed_at
            ?? $link->aiExtraction->upload->created_at;

        $invoiceItemCount = collect($items)->filter(fn (mixed $item): bool => is_array($item))->count();

        $sourceLine = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $match = $this->matchingPoItem($item, $link->poExtraction->items, $invoiceItemCount);
            $matchedItem = $match['item'] ?? null;
            $schedule = $matchedItem?->fulfillments->first()?->schedule;
            $arrivedQuantity = $this->normalizer->quantity($this->itemValue($item, ['quantity', 'qty', 'receivedQuantity']));
            $orderedQuantity = $matchedItem === null ? null : $this->normalizer->quantity($matchedItem->quantity);
            $targetQuantity = $schedule instanceof PurchaseOrderItemSchedule ? (float) $schedule->target_quantity : null;
            $unit = $this->itemValue($item, ['unit', 'uom'])
                ?? ($matchedItem instanceof PoExtractionItem ? $matchedItem->unit : null)
                ?? ($schedule instanceof PurchaseOrderItemSchedule ? $schedule->unit : null);

            PurchaseOrderItemArrival::query()->create([
                'source_key' => "ai:{$link->ai_extraction_id}:line:{$sourceLine}",
                'purchase_order_document_link_id' => $link->getKey(),
                'po_extraction_id' => $link->po_extraction_id,
                'ai_extraction_id' => $link->ai_extraction_id,
                'receiving_upload_id' => $link->aiExtraction->receiving_upload_id,
                'po_extraction_item_id' => $matchedItem?->getKey(),
                'purchase_order_item_schedule_id' => $schedule?->getKey(),
                'po_number' => $link->poExtraction->po_number,
                'po_date' => $poDate?->toDateString(),
                'arrival_date' => $arrivalDate->toDateString(),
                'po_week' => $poWeek,
                'item_code' => $this->itemValue($item, ['itemCode', 'item_code', 'sku', 'skuNumber', 'code']),
                'item_description' => $this->itemValue($item, ['description', 'productDescription', 'item', 'product', 'particulars']),
                'arrived_quantity' => $this->normalizer->decimalString($arrivedQuantity),
                'ordered_quantity' => $orderedQuantity === null ? null : $this->normalizer->decimalString($orderedQuantity),
                'target_quantity' => $targetQuantity === null ? null : $this->normalizer->decimalString($targetQuantity),
                'unit' => $unit,
                'matched_by' => $match['matched_by'] ?? 'unmatched',
                'status' => $this->arrivalStatus($arrivedQuantity, $orderedQuantity),
            ]);
            $sourceLine++;
        }
    }

    /**
     * @param  array<string, mixed>  $invoiceItem
     * @param  Collection<int, PoExtractionItem>  $poItems
     * @return array{item: PoExtractionItem, matched_by: string}|null
     */
    private function matchingPoItem(array $invoiceItem, Collection $poItems, int $invoiceItemCount): ?array
    {
        $invoiceCode = $this->itemValue($invoiceItem, ['itemCode', 'item_code', 'sku', 'skuNumber', 'code']);
        $invoiceDescription = $this->itemValue($invoiceItem, ['description', 'productDescription', 'item', 'product', 'particulars']);

        $candidates = $poItems
            ->map(function (PoExtractionItem $item) use ($invoiceCode, $invoiceDescription): ?array {
                $identifierMatch = $this->normalizer->identifierMatchType($invoiceCode, $item->item_code);
                if ($identifierMatch !== null) {
                    $descriptionScore = $this->normalizer->descriptionMatchScore(
                        $invoiceDescription,
                        $item->product_description,
                    ) ?? 0.0;

                    return [
                        'item' => $item,
                        'matched_by' => $identifierMatch,
                        'score' => ($identifierMatch === 'sku' ? 300.0 : 250.0) + ($descriptionScore * 10.0),
                        'sort_order' => $item->sort_order,
                        'id' => (int) $item->getKey(),
                    ];
                }

                $descriptionScore = $this->normalizer->descriptionMatchScore(
                    $invoiceDescription,
                    $item->product_description,
                );
                if ($descriptionScore === null) {
                    return null;
                }

                return [
                    'item' => $item,
                    'matched_by' => $descriptionScore < 1.0 ? 'description_partial' : 'description',
                    'score' => 100.0 + ($descriptionScore * 100.0),
                    'sort_order' => $item->sort_order,
                    'id' => (int) $item->getKey(),
                ];
            })
            ->filter()
            ->values();

        if ($candidates->isNotEmpty()) {
            $best = $candidates
                ->sort(function (array $left, array $right): int {
                    return [$right['score'], -$right['sort_order'], -$right['id']]
                        <=> [$left['score'], -$left['sort_order'], -$left['id']];
                })
                ->first();

            return is_array($best) && $best['item'] instanceof PoExtractionItem
                ? ['item' => $best['item'], 'matched_by' => $best['matched_by']]
                : null;
        }

        $invoiceQuantity = $this->normalizer->quantity(
            $this->itemValue($invoiceItem, ['quantity', 'qty', 'receivedQuantity']),
        );
        $invoiceUnit = $this->itemValue($invoiceItem, ['unit', 'uom']);
        if ($invoiceQuantity > 0) {
            $quantityMatches = $poItems
                ->filter(function (PoExtractionItem $item) use ($invoiceQuantity, $invoiceUnit): bool {
                    $poQuantity = $this->normalizer->quantity($item->quantity);

                    return abs($poQuantity - $invoiceQuantity) < 0.001
                        && $this->unitsCompatible($invoiceUnit, $item->unit);
                })
                ->values();

            if ($quantityMatches->count() === 1) {
                return ['item' => $quantityMatches->first(), 'matched_by' => 'quantity'];
            }
        }

        if ($invoiceItemCount === 1 && $poItems->count() === 1) {
            return ['item' => $poItems->first(), 'matched_by' => 'single_line'];
        }

        return null;
    }

    private function unitsCompatible(?string $left, ?string $right): bool
    {
        if (! $this->normalizer->hasMeaningfulValue($left)
            || ! $this->normalizer->hasMeaningfulValue($right)) {
            return true;
        }

        return $this->normalizer->normalizeIdentifier($left)
            === $this->normalizer->normalizeIdentifier($right);
    }

    private function arrivalStatus(float $arrivedQuantity, ?float $orderedQuantity): string
    {
        if ($orderedQuantity === null) {
            return 'unmatched';
        }

        if (abs($arrivedQuantity - $orderedQuantity) < 0.001) {
            return 'matched';
        }

        return $arrivedQuantity > $orderedQuantity ? 'over' : 'short';
    }

    /** @param array<string, mixed> $item @param array<int, string> $keys */
    private function itemValue(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $item)) {
                continue;
            }

            $value = $item[$key];
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $string = trim((string) $value);
            if ($this->normalizer->hasMeaningfulValue($string)) {
                return $string;
            }
        }

        return null;
    }

    private function linkStillMatches(AiExtraction $extraction, PoExtraction $poExtraction): bool
    {
        $poNumber = $this->normalizer->poNumber($this->dataFor($extraction) ?? []);
        $normalized = $this->normalizer->normalizeIdentifier($poNumber);

        return $normalized === null || $normalized === $poExtraction->po_number_normalized;
    }

    private function isStandardInvoiceOrReceipt(AiExtraction $extraction): bool
    {
        $extraction->loadMissing('upload.uploadType');
        if ($extraction->upload->uploadType->workflow !== UploadWorkflow::Standard) {
            return false;
        }

        $data = $this->dataFor($extraction);

        return $data !== null && $this->normalizer->isInvoiceOrReceipt($data);
    }

    /** @return array<string, mixed>|null */
    private function dataFor(AiExtraction $extraction): ?array
    {
        return $extraction->preferredData();
    }

    private function extractionHasActiveLink(AiExtraction $extraction): bool
    {
        return PurchaseOrderDocumentLink::query()
            ->active()
            ->where('ai_extraction_id', $extraction->getKey())
            ->exists();
    }

    private function poHasActiveLink(PoExtraction $poExtraction): bool
    {
        return PurchaseOrderDocumentLink::query()
            ->active()
            ->where('po_extraction_id', $poExtraction->getKey())
            ->exists();
    }
}
