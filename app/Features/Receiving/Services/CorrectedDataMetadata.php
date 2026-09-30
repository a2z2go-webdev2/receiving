<?php

namespace App\Features\Receiving\Services;

use Illuminate\Support\Str;

class CorrectedDataMetadata
{
    private const INVOICE_NUMBER_LABELS = [
        'invoice number',
        'invoice no',
        'invoice num',
        'invoice #',
    ];

    private const PO_NUMBER_LABELS = [
        'po number',
        'purchase order number',
        'purchase order no',
        'po no',
        'p.o. number',
        'p.o. no',
        'po #',
        'p.o. #',
        'purchase order',
        'purchase order #',
        'purchase order ref',
        'order number',
        'order no',
        'po',
        'p.o.',
        'po ref',
        'po reference',
        'customer po',
        'customer po number',
        'buyer po',
        'ref po',
        'ref po no',
        'po num',
        'p.o. num',
    ];

    private const PO_DATE_LABELS = [
        'po date',
        'purchase order date',
        'p.o. date',
    ];

    /** @param array<string, mixed>|null $correctedData */
    public static function invoiceNumber(?array $correctedData): ?string
    {
        return self::fieldValue($correctedData, self::INVOICE_NUMBER_LABELS, 100);
    }

    /** @param array<string, mixed>|null $correctedData */
    public static function poNumber(?array $correctedData): ?string
    {
        if ($correctedData === null) {
            return null;
        }

        // Check top-level keys first (mirrors PurchaseOrderDataNormalizer::poNumber)
        foreach (['po_number', 'poNumber', 'po'] as $key) {
            $topLevel = $correctedData[$key] ?? null;
            if (is_scalar($topLevel) && self::hasMeaningfulValue((string) $topLevel)) {
                $value = trim((string) $topLevel);

                return mb_strlen($value) <= 150 ? $value : null;
            }
        }

        return self::fieldValue($correctedData, self::PO_NUMBER_LABELS, 150);
    }

    /** @param array<string, mixed>|null $correctedData */
    public static function poDate(?array $correctedData): ?string
    {
        return self::fieldValue($correctedData, self::PO_DATE_LABELS, 100);
    }

    public static function normalizedIdentifier(?string $value): ?string
    {
        if ($value === null || ! self::hasMeaningfulValue($value)) {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/i', '', Str::lower($value)) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    /** @param array<string, mixed>|null $correctedData */
    private static function fieldValue(?array $correctedData, array $labels, int $maxLength): ?string
    {
        $fields = $correctedData['fields'] ?? null;
        if (! is_array($fields)) {
            return null;
        }

        $allowedLabels = collect($labels)->map(fn (string $label): string => self::normalizeLabel($label))->all();

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (! in_array(self::normalizeLabel((string) ($field['label'] ?? '')), $allowedLabels, true)) {
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));

            return self::hasMeaningfulValue($value) && mb_strlen($value) <= $maxLength ? $value : null;
        }

        return null;
    }

    private static function normalizeLabel(string $label): string
    {
        return Str::of($label)
            ->lower()
            ->replaceMatches('/[^\pL\pN#]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->value();
    }

    private static function hasMeaningfulValue(string $value): bool
    {
        $trimmed = trim($value);

        return $trimmed !== '' && Str::lower($trimmed) !== '[see image]' && $trimmed !== '###';
    }
}
