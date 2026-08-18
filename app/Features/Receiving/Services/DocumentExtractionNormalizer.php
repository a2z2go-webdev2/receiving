<?php

namespace App\Features\Receiving\Services;

use App\Enums\UploadWorkflow;
use Illuminate\Support\Str;

class DocumentExtractionNormalizer
{
    private const INVOICE_FIELDS = [
        'Company Name',
        'Address',
        'TIN',
        'Invoice Number',
        'PO Number',
        'PO Date',
        'Invoice Date',
        'Waiting Time',
        'Gross',
        'Input Tax',
        'Purchases',
        'Buyer Address',
    ];

    private const REMOVED_FIELDS = ['atc', 'account title', 'ewt 1', 'ewt 2', 'ewt 1%', 'ewt 2%'];

    private const PURCHASE_ORDER_FIELDS = [
        'PO Number' => true,
        'PO Reference' => true,
        'PO Date' => true,
        'Buyer Company' => true,
        'Buyer Address' => false,
        'Buyer Contact Numbers' => false,
        'Vendor Name' => true,
        'Contact Person' => true,
        'Vendor Email' => false,
        'Vendor Mobile' => false,
        'Vendor Address' => false,
        'Payment Terms' => true,
        'Subtotal' => false,
        'VAT' => false,
        'Total Amount' => false,
    ];

    private const PURCHASE_ORDER_ITEM_KEYS = [
        'itemCode',
        'productDescription',
        'package',
        'quantity',
        'unit',
        'unitPrice',
        'lineTotal',
    ];

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function normalize(array $data, UploadWorkflow $workflow = UploadWorkflow::Standard): array
    {
        $document = $this->firstDocument($data);
        if ($workflow === UploadWorkflow::PurchaseOrder) {
            return $this->purchaseOrder($document);
        }

        $documentType = $this->documentType($document['documentType'] ?? $document['document_type'] ?? null);
        $fields = $this->fields($document['fields'] ?? []);

        if ($this->isInvoice($documentType)) {
            $existing = collect($fields)->pluck('label')->map(fn (string $label): string => Str::lower($label));
            foreach (self::INVOICE_FIELDS as $label) {
                if (! $existing->contains(Str::lower($label))) {
                    $fields[] = ['label' => $label, 'value' => '[See image]'];
                }
            }
        }

        $normalized = [
            'document_type' => $documentType,
            'fields' => $fields,
            'items' => $this->items($document['items'] ?? []),
        ];

        if ($this->isInvoice($documentType)) {
            $values = collect($fields)->mapWithKeys(
                fn (array $field): array => [Str::lower($field['label']) => $field['value']],
            );
            $gross = filter_var($values->get('gross'), FILTER_VALIDATE_FLOAT);
            $purchases = filter_var($values->get('purchases'), FILTER_VALIDATE_FLOAT);
            if ($gross !== false && $purchases !== false && $gross <= $purchases) {
                $normalized['_warnings'] = ['Gross should be greater than purchases; verify these values against the document.'];
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function purchaseOrder(array $document): array
    {
        $values = collect($this->fields($document['fields'] ?? []))->mapWithKeys(
            fn (array $field): array => [$this->normalizedKey($field['label']) => $field['value']],
        );

        $fields = collect(self::PURCHASE_ORDER_FIELDS)
            ->map(fn (bool $required, string $label): array => [
                'label' => $label,
                'value' => (string) ($values->get($this->normalizedKey($label)) ?? ($required ? '[See image]' : '')),
            ])
            ->values()
            ->all();

        $items = collect(is_array($document['items'] ?? null) ? $document['items'] : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $values = collect($item)->mapWithKeys(
                    fn (mixed $value, string|int $key): array => [$this->normalizedKey((string) $key) => $this->stringValue($value)],
                );

                return collect(self::PURCHASE_ORDER_ITEM_KEYS)
                    ->mapWithKeys(fn (string $key): array => [
                        $key => (string) ($values->get($this->normalizedKey($key)) ?? '[See image]'),
                    ])
                    ->all();
            })
            ->values()
            ->all();

        return [
            'document_type' => 'Purchase Order',
            'fields' => $fields,
            'items' => $items,
        ];
    }

    private function normalizedKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower($value)) ?? '';
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function firstDocument(array $data): array
    {
        if (! isset($data['documents']) || ! is_array($data['documents']) || ! is_array($data['documents'][0] ?? null)) {
            return $data;
        }

        return $data['documents'][0];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function fields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        return collect($fields)
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => [
                'label' => trim((string) ($field['label'] ?? '')),
                'value' => $this->stringValue($field['value'] ?? ''),
            ])
            ->filter(fn (array $field): bool => $field['label'] !== '' && ! in_array(Str::lower($field['label']), self::REMOVED_FIELDS, true))
            ->values()
            ->all();
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
                $normalized = [];
                foreach ($item as $key => $value) {
                    if (is_string($key) && trim($key) !== '') {
                        $normalized[$key] = $this->stringValue($value);
                    }
                }

                return $normalized;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function documentType(mixed $value): string
    {
        $type = trim(is_string($value) ? $value : '');

        return $type === '' ? 'Other' : Str::of($type)->replace(['_', '-'], ' ')->title()->value();
    }

    private function isInvoice(string $documentType): bool
    {
        return Str::contains(Str::lower($documentType), ['invoice', 'billing']);
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '[See image]';
    }
}
