<?php

namespace App\Features\Receiving\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class PurchaseOrderDataNormalizer
{
    private const DESCRIPTION_STOP_WORDS = [
        'a', 'an', 'and', 'for', 'from', 'in', 'of', 'or', 'the', 'to', 'with',
    ];

    /** @param array<string, mixed> $data */
    public function poNumber(array $data): ?string
    {
        return $this->fieldValue($data, ['PO Number', 'Purchase Order Number', 'PO No', 'P.O. No']);
    }

    /** @param array<string, mixed> $data */
    public function poDate(array $data): ?string
    {
        return $this->fieldValue($data, ['PO Date', 'Purchase Order Date', 'P.O. Date']);
    }

    /** @param array<string, mixed> $data */
    public function isInvoiceOrReceipt(array $data): bool
    {
        $documentType = Str::lower(trim((string) ($data['document_type'] ?? $data['documentType'] ?? '')));

        return Str::contains($documentType, ['invoice', 'receipt', 'billing', 'delivery receipt', 'proof of receipt']);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function withFieldValue(array $data, string $label, string $value): array
    {
        $fields = $data['fields'] ?? [];
        if (! is_array($fields)) {
            $data['fields'] = [['label' => $label, 'value' => $value]];

            return $data;
        }

        $wanted = $this->fieldKey($label);
        foreach ($fields as $index => $field) {
            if (! is_array($field) || $this->fieldKey((string) ($field['label'] ?? '')) !== $wanted) {
                continue;
            }

            $fields[$index] = [
                'label' => (string) ($field['label'] ?? $label),
                'value' => $value,
            ];
            $data['fields'] = array_values($fields);

            return $data;
        }

        $fields[] = ['label' => $label, 'value' => $value];
        $data['fields'] = array_values($fields);

        return $data;
    }

    public function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null || ! $this->hasMeaningfulValue($value)) {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/i', '', Str::lower($value)) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    public function normalizeDescription(?string $value): ?string
    {
        if ($value === null || ! $this->hasMeaningfulValue($value)) {
            return null;
        }

        $normalized = Str::of($value)
            ->lower()
            ->replaceMatches('/(?<=\d)(?=\pL)|(?<=\pL)(?=\d)/u', ' ')
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->limit(500, '')
            ->value();

        return $normalized === '' ? null : $normalized;
    }

    public function identifierMatchType(?string $left, ?string $right): ?string
    {
        $leftNormalized = $this->normalizeIdentifier($left);
        $rightNormalized = $this->normalizeIdentifier($right);

        if ($leftNormalized === null || $rightNormalized === null) {
            return null;
        }

        if ($leftNormalized === $rightNormalized) {
            return 'sku';
        }

        $shorter = mb_strlen($leftNormalized) <= mb_strlen($rightNormalized)
            ? $leftNormalized
            : $rightNormalized;
        $longer = $shorter === $leftNormalized ? $rightNormalized : $leftNormalized;

        if (mb_strlen($shorter) < 5) {
            return null;
        }

        return str_starts_with($longer, $shorter) || str_contains($longer, $shorter)
            ? 'base_sku'
            : null;
    }

    public function descriptionMatchScore(?string $left, ?string $right): ?float
    {
        $leftNormalized = $this->normalizeDescription($left);
        $rightNormalized = $this->normalizeDescription($right);

        if ($leftNormalized === null || $rightNormalized === null) {
            return null;
        }

        if ($leftNormalized === $rightNormalized) {
            return 1.0;
        }

        $shorter = mb_strlen($leftNormalized) <= mb_strlen($rightNormalized)
            ? $leftNormalized
            : $rightNormalized;
        $longer = $shorter === $leftNormalized ? $rightNormalized : $leftNormalized;

        if (mb_strlen($shorter) >= 8 && str_contains($longer, $shorter)) {
            return 0.92;
        }

        $leftTokens = $this->descriptionTokens($leftNormalized);
        $rightTokens = $this->descriptionTokens($rightNormalized);
        if ($leftTokens === [] || $rightTokens === []) {
            return null;
        }

        $shared = array_values(array_intersect($leftTokens, $rightTokens));
        if ($shared === []) {
            return null;
        }

        $strongShared = array_filter(
            $shared,
            fn (string $token): bool => mb_strlen($token) >= 3 || preg_match('/\d/', $token) === 1,
        );
        $shorterCount = min(count($leftTokens), count($rightTokens));
        if (count($strongShared) < 2 && ! (count($strongShared) === 1 && $shorterCount === 1)) {
            return null;
        }

        $coverage = count($shared) / max(1, $shorterCount);
        $jaccard = count($shared) / max(1, count(array_unique([...$leftTokens, ...$rightTokens])));
        $score = max($jaccard, $coverage * 0.85);

        return $score >= 0.68 ? min(0.9, $score) : null;
    }

    public function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! $this->hasMeaningfulValue($value)) {
            return null;
        }

        $value = trim($value);
        $formats = [
            'Y-m-d', 'm/d/Y', 'd/m/Y', 'm-d-Y', 'd-m-Y',
            'F j, Y', 'j F Y', 'M j, Y', 'j M Y',
        ];

        foreach ($formats as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
                if ($date instanceof CarbonImmutable) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                // Try the next common document date format.
            }
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function weekOfMonth(CarbonInterface $date): int
    {
        return min(4, intdiv($date->day - 1, 7) + 1);
    }

    public function waitingDays(?CarbonInterface $poDate, ?CarbonInterface $arrivalDate): ?int
    {
        if ($poDate === null || $arrivalDate === null) {
            return null;
        }

        $seconds = $arrivalDate->copy()->startOfDay()->getTimestamp()
            - $poDate->copy()->startOfDay()->getTimestamp();

        if ($seconds < 0) {
            return null;
        }

        return (int) floor($seconds / 86400);
    }

    public function quantity(?string $value): float
    {
        if ($value === null || ! $this->hasMeaningfulValue($value)) {
            return 0.0;
        }

        $candidate = str_replace(',', '', $value);
        if (preg_match('/-?\d+(?:\.\d+)?/', $candidate, $matches) !== 1) {
            return 0.0;
        }

        return abs((float) $matches[0]);
    }

    public function decimalString(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    public function eanMatchType(?string $left, ?string $right): ?string
    {
        $type = $this->identifierMatchType($left, $right);
        if ($type === null) {
            return null;
        }

        return $type === 'sku' ? 'ean' : 'base_ean';
    }

    /** @return array{main_units: float, package_multiplier: float, calculated_total: float}|null */
    public function parsePackageString(?string $value): ?array
    {
        if ($value === null || ! $this->hasMeaningfulValue($value)) {
            return null;
        }

        $trimmed = trim($value);
        if (preg_match('/^([\d\.,]+)\s*(?:\(\s*([\d\.,]+)\s*\)|[x\*]\s*([\d\.,]+))/i', $trimmed, $matches)) {
            $mainUnits = $this->quantity($matches[1]);
            $multiplier = $this->quantity($matches[2] ?? $matches[3] ?? '1');

            if ($mainUnits > 0 && $multiplier > 0) {
                return [
                    'main_units' => $mainUnits,
                    'package_multiplier' => $multiplier,
                    'calculated_total' => $mainUnits * $multiplier,
                ];
            }
        }

        $singleQty = $this->quantity($trimmed);
        if ($singleQty > 0) {
            return [
                'main_units' => $singleQty,
                'package_multiplier' => 1.0,
                'calculated_total' => $singleQty,
            ];
        }

        return null;
    }

    public function hasMeaningfulValue(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $value = trim($value);

        return $value !== '' && Str::lower($value) !== '[see image]' && $value !== '###';
    }

    /** @return array<int, string> */
    private function descriptionTokens(string $value): array
    {
        return collect(explode(' ', $value))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => $token !== '' && ! in_array($token, self::DESCRIPTION_STOP_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function fieldValue(array $data, array $labels): ?string
    {
        $fields = $data['fields'] ?? null;
        if (! is_array($fields)) {
            return null;
        }

        $wanted = array_map(fn (string $label): string => $this->fieldKey($label), $labels);
        foreach ($fields as $field) {
            if (! is_array($field) || ! in_array($this->fieldKey((string) ($field['label'] ?? '')), $wanted, true)) {
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));

            return $this->hasMeaningfulValue($value) ? $value : null;
        }

        return null;
    }

    private function fieldKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', Str::lower($value)) ?? '';
    }
}
