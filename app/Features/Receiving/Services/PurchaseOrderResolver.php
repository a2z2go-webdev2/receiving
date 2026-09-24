<?php

namespace App\Features\Receiving\Services;

use App\Enums\PurchaseOrderLinkStatus;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PurchaseOrderResolution
{
    /**
     * @param  Collection<int, PoExtraction>  $candidates
     */
    public function __construct(
        public readonly PurchaseOrderLinkStatus $status,
        public readonly ?PoExtraction $selectedPo = null,
        public readonly Collection $candidates = new Collection,
        public readonly string $matchMethod = 'none',
        public readonly ?string $conflictReason = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === PurchaseOrderLinkStatus::Linked && $this->selectedPo !== null;
    }
}

class PurchaseOrderResolver
{
    public function __construct(
        private readonly PurchaseOrderDataNormalizer $normalizer,
    ) {}

    /**
     * Resolve the eligible Purchase Order for an extraction.
     */
    public function resolve(AiExtraction $extraction): PurchaseOrderResolution
    {
        $data = $this->dataFor($extraction);
        if ($data === null) {
            return new PurchaseOrderResolution(PurchaseOrderLinkStatus::NotApplicable);
        }

        $poNumber = $this->normalizer->poNumber($data);
        $normalizedPoNumber = $this->normalizer->normalizeIdentifier($poNumber);

        if ($normalizedPoNumber === null) {
            return $this->resolveWithoutPoNumber($data);
        }

        /** @var Collection<int, PoExtraction> $matchingPos */
        $matchingPos = PoExtraction::query()
            ->where('po_number_normalized', $normalizedPoNumber)
            ->orderByDesc('po_date_value')
            ->orderByDesc('id')
            ->get();

        if ($matchingPos->isEmpty()) {
            return new PurchaseOrderResolution(PurchaseOrderLinkStatus::AwaitingPurchaseOrder);
        }

        $eligiblePos = $matchingPos->filter(fn (PoExtraction $po): bool => $this->isPoEligible($po));

        if ($eligiblePos->isEmpty()) {
            return new PurchaseOrderResolution(
                PurchaseOrderLinkStatus::AwaitingPurchaseOrder,
                null,
                $matchingPos,
                'ineligible_status',
                "Order {$poNumber} is in preparation or ineligible for linking."
            );
        }

        $documentVendor = $this->extractVendorName($data);

        if ($eligiblePos->count() === 1) {
            /** @var PoExtraction $candidate */
            $candidate = $eligiblePos->first();

            if ($documentVendor !== null && $candidate->vendor_name !== null) {
                if ($this->hasSupplierConflict($documentVendor, $candidate->vendor_name)) {
                    return new PurchaseOrderResolution(
                        PurchaseOrderLinkStatus::Conflict,
                        $candidate,
                        $eligiblePos,
                        'supplier_conflict',
                        "Extracted vendor '{$documentVendor}' conflicts with PO vendor '{$candidate->vendor_name}'."
                    );
                }
            }

            return new PurchaseOrderResolution(
                PurchaseOrderLinkStatus::Linked,
                $candidate,
                $eligiblePos,
                'exact_number'
            );
        }

        // Multiple eligible POs with the same normalized number
        $uniqueVendors = $eligiblePos->pluck('vendor_name')->filter()->unique();
        if ($uniqueVendors->count() > 1) {
            return new PurchaseOrderResolution(
                PurchaseOrderLinkStatus::Ambiguous,
                null,
                $eligiblePos,
                'identifier_collision',
                "Multiple active POs found with conflicting suppliers for PO number {$poNumber}."
            );
        }

        /** @var PoExtraction $best */
        $best = $eligiblePos->sortByDesc('po_date_value')->first();

        return new PurchaseOrderResolution(
            PurchaseOrderLinkStatus::Linked,
            $best,
            $eligiblePos,
            'latest_version'
        );
    }

    public function isPoEligible(PoExtraction $po): bool
    {
        if ($po->source_type === 'google_sheet') {
            return in_array($po->status_normalized, ['confirmed', 'received'], true);
        }

        return true;
    }

    /**
     * Propose candidates when PO number is missing using supplier and item evidence.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveWithoutPoNumber(array $data): PurchaseOrderResolution
    {
        $vendor = $this->extractVendorName($data);
        if ($vendor === null) {
            return new PurchaseOrderResolution(PurchaseOrderLinkStatus::MissingPoNumber);
        }

        $tokens = $this->vendorTokens($vendor);
        if (empty($tokens)) {
            return new PurchaseOrderResolution(PurchaseOrderLinkStatus::MissingPoNumber);
        }

        $query = PoExtraction::query()
            ->whereNotNull('po_number_normalized')
            ->where(function ($q) use ($tokens): void {
                foreach ($tokens as $token) {
                    $q->orWhere('vendor_name', 'like', "%{$token}%");
                }
            })
            ->orderByDesc('po_date_value')
            ->limit(10);

        /** @var Collection<int, PoExtraction> $candidates */
        $candidates = $query->get()->filter(fn (PoExtraction $po): bool => $this->isPoEligible($po));

        if ($candidates->isEmpty()) {
            return new PurchaseOrderResolution(PurchaseOrderLinkStatus::MissingPoNumber);
        }

        if ($candidates->count() === 1) {
            return new PurchaseOrderResolution(
                PurchaseOrderLinkStatus::ReadyToLink,
                $candidates->first(),
                $candidates,
                'supplier_candidate'
            );
        }

        return new PurchaseOrderResolution(
            PurchaseOrderLinkStatus::Ambiguous,
            null,
            $candidates,
            'supplier_ambiguity',
            'Multiple candidate POs match the document vendor.'
        );
    }

    /**
     * Check if two supplier/vendor names have an irreconcilable conflict.
     */
    public function hasSupplierConflict(string $left, string $right): bool
    {
        $leftTokens = $this->vendorTokens($left);
        $rightTokens = $this->vendorTokens($right);

        if (empty($leftTokens) || empty($rightTokens)) {
            return false;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);

        return empty($intersection);
    }

    /**
     * @return array<int, string>
     */
    private function vendorTokens(string $name): array
    {
        $stopWords = ['inc', 'corp', 'corporation', 'llc', 'ltd', 'limited', 'co', 'company', 'enterprises', 'phils', 'philippines', 'trading', 'the'];

        $cleaned = Str::lower(preg_replace('/[^a-z0-9\s]/i', ' ', $name) ?? '');
        $words = preg_split('/\s+/', $cleaned, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $words,
            fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, $stopWords, true)
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractVendorName(array $data): ?string
    {
        $fields = $data['fields'] ?? [];
        if (! is_array($fields)) {
            return null;
        }

        $vendorKeys = ['supplier', 'vendor', 'vendor name', 'supplier name', 'seller', 'company'];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = Str::lower(trim((string) ($field['label'] ?? '')));
            if (in_array($label, $vendorKeys, true)) {
                $val = trim((string) ($field['value'] ?? ''));
                if ($this->normalizer->hasMeaningfulValue($val)) {
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dataFor(AiExtraction $extraction): ?array
    {
        return $extraction->preferredData() ?? $extraction->raw_extracted_json;
    }
}
