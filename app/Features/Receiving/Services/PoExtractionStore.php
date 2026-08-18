<?php

namespace App\Features\Receiving\Services;

use App\Models\AiExtraction;
use App\Models\PoExtraction;
use Illuminate\Support\Facades\DB;

class PoExtractionStore
{
    public function __construct(
        private readonly PurchaseOrderDataNormalizer $normalizer,
        private readonly PurchaseOrderItemMatcher $matcher,
        private readonly PurchaseOrderDataIntegrator $integrator,
        private readonly PurchaseOrderLinker $linker,
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedData
     */
    public function store(AiExtraction $aiExtraction, array $normalizedData): PoExtraction
    {
        $fields = $this->extractFields($normalizedData);
        $poNumber = $this->val($fields, 'po_number');
        $poDate = $this->val($fields, 'po_date');

        $poExtraction = DB::transaction(function () use ($aiExtraction, $normalizedData, $fields, $poNumber, $poDate): PoExtraction {
            $poExtraction = PoExtraction::query()->updateOrCreate(
                ['ai_extraction_id' => $aiExtraction->getKey()],
                [
                    'receiving_upload_id' => $aiExtraction->receiving_upload_id,
                    'po_number' => $poNumber,
                    'po_number_normalized' => $this->normalizer->normalizeIdentifier($poNumber),
                    'po_reference' => $this->val($fields, 'po_reference'),
                    'po_date' => $poDate,
                    'po_date_value' => $this->normalizer->parseDate($poDate)?->toDateString(),
                    'buyer_company' => $this->val($fields, 'buyer_company'),
                    'buyer_address' => $this->val($fields, 'buyer_address'),
                    'buyer_contact_numbers' => $this->val($fields, 'buyer_contact_numbers'),
                    'vendor_name' => $this->val($fields, 'vendor_name'),
                    'contact_person' => $this->val($fields, 'contact_person'),
                    'vendor_email' => $this->val($fields, 'vendor_email'),
                    'vendor_mobile' => $this->val($fields, 'vendor_mobile'),
                    'vendor_address' => $this->val($fields, 'vendor_address'),
                    'payment_terms' => $this->val($fields, 'payment_terms'),
                    'subtotal' => $this->val($fields, 'subtotal'),
                    'vat' => $this->val($fields, 'vat'),
                    'total_amount' => $this->val($fields, 'total_amount'),
                ]
            );

            $poExtraction->items()->delete();

            $items = $normalizedData['items'] ?? [];
            if (is_array($items)) {
                $itemModels = [];
                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $rawQty = $this->valRaw($item, 'quantity');
                    $package = $this->valRaw($item, 'package');
                    $quantity = $rawQty;

                    if ($quantity === null && $package !== null) {
                        $parsedPackage = $this->normalizer->parsePackageString($package);
                        if ($parsedPackage !== null) {
                            $quantity = (string) $parsedPackage['calculated_total'];
                        }
                    }

                    $unitPrice = $this->valRaw($item, 'unitPrice', 'unit_price', 'vat_ex_price', 'vatExPrice');
                    $lineTotal = $this->valRaw($item, 'lineTotal', 'line_total', 'total');

                    if ($lineTotal === null && $quantity !== null && $unitPrice !== null) {
                        $qtyFloat = $this->normalizer->quantity($quantity);
                        $priceFloat = $this->normalizer->quantity($unitPrice);
                        if ($qtyFloat > 0 && $priceFloat > 0) {
                            $lineTotal = number_format($qtyFloat * $priceFloat, 4, '.', '');
                        }
                    }

                    $itemModels[] = [
                        'sort_order' => $index,
                        'item_code' => $this->valRaw($item, 'itemCode', 'item_code'),
                        'product_description' => $this->valRaw($item, 'productDescription', 'product_description'),
                        'package' => $package,
                        'quantity' => $quantity,
                        'unit' => $this->valRaw($item, 'unit'),
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ];
                }
                if ($itemModels !== []) {
                    $poExtraction->items()->createMany($itemModels);
                }
            }

            $poExtraction->load('items');
            $this->matcher->sync($poExtraction);

            return $poExtraction;
        });

        $this->integrator->backfillMatchingStandardExtractions($poExtraction);
        $this->linker->syncPoExtraction($poExtraction);

        return $poExtraction;
    }

    /**
     * @param  array<string, mixed>  $normalizedData
     * @return array<string, string>
     */
    private function extractFields(array $normalizedData): array
    {
        if (! isset($normalizedData['fields']) || ! is_array($normalizedData['fields'])) {
            return [];
        }

        $fields = [];
        foreach ($normalizedData['fields'] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = $field['label'] ?? '';
            $value = trim((string) ($field['value'] ?? ''));
            $key = $this->fieldKey((string) $label);

            if ($value !== '[See image]') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function val(array $fields, string $key): ?string
    {
        $value = $fields[$this->fieldKey($key)] ?? null;

        return $value !== '' ? $value : null;
    }

    private function fieldKey(string $value): string
    {
        return strtolower(trim(preg_replace('/[\s\-]+/', '_', $value) ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function valRaw(array $item, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($item[$key])) {
                $value = trim((string) $item[$key]);
                if ($value !== '' && $value !== '[See image]') {
                    return $value;
                }
            }
        }

        return null;
    }
}
