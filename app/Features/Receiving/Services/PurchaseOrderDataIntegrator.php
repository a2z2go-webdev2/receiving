<?php

namespace App\Features\Receiving\Services;

use App\Enums\UploadWorkflow;
use App\Models\AiExtraction;
use App\Models\PoExtraction;

class PurchaseOrderDataIntegrator
{
    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function fillMissingPoDate(array $data): array
    {
        if (! $this->normalizer->isInvoiceOrReceipt($data)
            || $this->normalizer->poDate($data) !== null) {
            return $data;
        }

        $poNumber = $this->normalizer->poNumber($data);
        $normalizedPoNumber = $this->normalizer->normalizeIdentifier($poNumber);
        if ($normalizedPoNumber === null) {
            return $data;
        }

        $source = PoExtraction::query()
            ->where('po_number_normalized', $normalizedPoNumber)
            ->whereNotNull('po_date')
            ->where('po_date', '!=', '')
            ->where('po_date', '!=', '[See image]')
            ->orderByDesc('po_date_value')
            ->orderByDesc('id')
            ->first(['id', 'po_date']);

        if ($source === null || ! $this->normalizer->hasMeaningfulValue($source->po_date)) {
            return $data;
        }

        return $this->normalizer->withFieldValue($data, 'PO Date', (string) $source->po_date);
    }

    public function backfillMatchingStandardExtractions(PoExtraction $poExtraction): int
    {
        if ($poExtraction->po_number_normalized === null
            || ! $this->normalizer->hasMeaningfulValue($poExtraction->po_date)) {
            return 0;
        }

        $updated = 0;

        AiExtraction::query()
            ->with('upload.uploadType')
            ->where('po_number_normalized', $poExtraction->po_number_normalized)
            ->where(function ($query): void {
                $query->whereNull('po_date')
                    ->orWhere('po_date', '')
                    ->orWhere('po_date', '[See image]');
            })
            ->chunkById(100, function ($extractions) use ($poExtraction, &$updated): void {
                foreach ($extractions as $extraction) {
                    if ($extraction->upload->uploadType->workflow !== UploadWorkflow::Standard) {
                        continue;
                    }

                    $raw = is_array($extraction->raw_extracted_json) ? $extraction->raw_extracted_json : null;
                    if ($raw === null || ! $this->normalizer->isInvoiceOrReceipt($raw)) {
                        continue;
                    }

                    $filledRaw = $this->normalizer->withFieldValue($raw, 'PO Date', (string) $poExtraction->po_date);
                    $filledCorrected = $extraction->corrected_json;
                    if (is_array($filledCorrected)
                        && $this->normalizer->poDate($filledCorrected) === null
                        && $this->normalizer->isInvoiceOrReceipt($filledCorrected)) {
                        $filledCorrected = $this->normalizer->withFieldValue($filledCorrected, 'PO Date', (string) $poExtraction->po_date);
                    }

                    $extraction->forceFill([
                        'raw_extracted_json' => $filledRaw,
                        'corrected_json' => $filledCorrected,
                        'po_date_filled_from_po_extraction_id' => $poExtraction->getKey(),
                    ])->save();
                    $updated++;
                }
            });

        return $updated;
    }
}
