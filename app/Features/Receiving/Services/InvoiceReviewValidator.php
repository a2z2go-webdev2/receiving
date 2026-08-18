<?php

namespace App\Features\Receiving\Services;

use App\Models\AiExtraction;
use App\Models\PoExtraction;
use Illuminate\Support\Str;

class InvoiceReviewValidator
{
    private const REMOVED_KEYS = ['atc', 'account_title', 'ewt_1_percent', 'ewt_2_percent'];

    private const REMOVED_LABELS = ['atc', 'account title', 'ewt 1', 'ewt 2', 'ewt 1%', 'ewt 2%'];

    private const LABELS = [
        'po_number' => 'PO Number',
        'supplier_name' => 'Company Name',
        'supplier_address' => 'Address',
        'supplier_tin' => 'TIN',
        'invoice_number' => 'Invoice Number',
        'po_date' => 'PO Date',
        'invoice_date' => 'Invoice Date',
        'waiting_time' => 'Waiting Time',
        'input_tax' => 'Input Tax',
        'purchases' => 'Purchases',
        'gross' => 'Gross',
        'buyer_address' => 'Buyer Address',
    ];

    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function normalize(array $data, bool $forVerification, ?AiExtraction $extraction = null): array
    {
        $documentType = trim((string) ($data['document_type'] ?? ''));
        $fields = is_array($data['fields'] ?? null)
            ? $this->structuredFields($data['fields'])
            : $this->legacyFields($data);

        if ($extraction && $extraction->activePurchaseOrderLink) {
            $linkedPo = $extraction->activePurchaseOrderLink->poExtraction;
            $poDate = $linkedPo instanceof PoExtraction ? $linkedPo->po_date_value : null;
            $poDate ??= $this->normalizer->parseDate($extraction->po_date ?? ($data['po_date'] ?? null));
            $arrivalDate = $extraction->upload->upload_completed_at ?? $extraction->upload->created_at;
            $waitingDays = $this->normalizer->waitingDays($poDate, $arrivalDate);

            foreach ($fields as $index => $field) {
                if (Str::lower(trim($field['label'])) === 'waiting time') {
                    $fields[$index]['value'] = $waitingDays !== null ? "{$waitingDays} days" : '[See image]';
                    break;
                }
            }
        }

        $warnings = $this->warnings($data);

        return [
            'document_type' => $documentType === '' ? 'Other' : $documentType,
            'fields' => $fields,
            'items' => $this->items($data['items'] ?? []),
            ...($warnings === [] ? [] : ['_warnings' => $warnings]),
        ];
    }

    /** @param array<int|string, mixed> $fields @return array<int, array{label: string, value: string}> */
    private function structuredFields(array $fields): array
    {
        return collect($fields)
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => [
                'label' => trim((string) ($field['label'] ?? '')),
                'value' => $this->stringValue($field['value'] ?? ''),
            ])
            ->filter(fn (array $field): bool => $field['label'] !== '' && ! in_array(Str::lower($field['label']), self::REMOVED_LABELS, true))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data @return array<int, array{label: string, value: string}> */
    private function legacyFields(array $data): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['document_type', 'items', '_warnings', ...self::REMOVED_KEYS], true)) {
                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $fields[] = [
                'label' => self::LABELS[$key] ?? Str::of($key)->replace('_', ' ')->title()->value(),
                'value' => $this->stringValue($value),
            ];
        }

        return $fields;
    }

    /** @return array<int, array<string, string>> */
    private function items(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $values = [];
                foreach ($item as $key => $value) {
                    if (is_string($key) && trim($key) !== '') {
                        $values[$key] = $this->stringValue($value);
                    }
                }

                return $values;
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function warnings(array $data): array
    {
        return is_array($data['_warnings'] ?? null)
            ? collect($data['_warnings'])->filter('is_string')->values()->all()
            : [];
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
