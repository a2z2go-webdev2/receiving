<?php

namespace App\Services\GoogleSheets;

use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use Illuminate\Support\Str;

class PurchaseOrderSheetParser
{
    public function __construct(
        private readonly PurchaseOrderDataNormalizer $normalizer
    ) {}

    /**
     * Parse raw sheet rows (associative or row matrices) into validated grouped Purchase Orders.
     *
     * @param  array<int, array<string, mixed>|array<int, mixed>>  $rows
     * @return array{
     *     orders: array<string, array{
     *         po_number: string,
     *         po_number_normalized: string,
     *         supplier: string,
     *         type: string,
     *         raw_status: string,
     *         status_normalized: string,
     *         is_eligible: bool,
     *         po_date: string|null,
     *         po_date_value: string|null,
     *         net_total: string|null,
     *         vat_total: string|null,
     *         total_amount: string|null,
     *         notes: string|null,
     *         items: array<int, array{
     *             sort_order: int,
     *             source_line_id: string,
     *             item_code: string|null,
     *             product_description: string|null,
     *             quantity: string|null,
     *             unit: string|null,
     *             unit_price: string|null,
     *             line_total: string|null,
     *             is_financial_adjustment: bool
     *         }>,
     *         validation_errors: array<int, string>,
     *         row_hash: string
     *     }>,
     *     invalid_rows: array<int, array{row: mixed, errors: array<int, string>}>
     * }
     */
    public function parse(array $rows): array
    {
        $assocRows = $this->ensureAssociativeRows($rows);

        $grouped = [];
        $invalidRows = [];

        foreach ($assocRows as $rowIndex => $rawRow) {
            $mapped = $this->mapRow($rawRow);
            $poNumber = trim((string) ($mapped['po_number'] ?? ''));

            if ($poNumber === '') {
                $invalidRows[] = [
                    'row' => $rawRow,
                    'errors' => ["Row {$rowIndex}: Missing PO number."],
                ];

                continue;
            }

            $normalizedPoNumber = $this->normalizer->normalizeIdentifier($poNumber);
            if ($normalizedPoNumber === null) {
                $invalidRows[] = [
                    'row' => $rawRow,
                    'errors' => ["Row {$rowIndex}: Could not normalize PO number '{$poNumber}'."],
                ];

                continue;
            }

            $orderKey = $normalizedPoNumber;

            if (! isset($grouped[$orderKey])) {
                $rawStatus = trim((string) ($mapped['status'] ?? ''));
                $statusNormalized = $this->normalizeStatus($rawStatus);
                $isEligible = in_array($statusNormalized, ['confirmed', 'received'], true);

                $poDateStr = trim((string) ($mapped['date'] ?? ''));
                $parsedDate = $this->normalizer->parseDate($poDateStr);

                $grouped[$orderKey] = [
                    'po_number' => $poNumber,
                    'po_number_normalized' => $normalizedPoNumber,
                    'supplier' => trim((string) ($mapped['supplier'] ?? '')),
                    'type' => trim((string) ($mapped['type'] ?? '')),
                    'raw_status' => $rawStatus,
                    'status_normalized' => $statusNormalized,
                    'is_eligible' => $isEligible,
                    'po_date' => $poDateStr !== '' ? $poDateStr : null,
                    'po_date_value' => $parsedDate?->toDateString(),
                    'net_total' => $this->cleanAmount($mapped['net_total'] ?? null),
                    'vat_total' => $this->cleanAmount($mapped['vat_total'] ?? null),
                    'total_amount' => $this->cleanAmount($mapped['total_sum'] ?? null),
                    'notes' => trim((string) ($mapped['notes'] ?? '')) ?: null,
                    'items' => [],
                    'validation_errors' => [],
                    'row_hash' => '',
                ];

                if (! in_array($statusNormalized, ['in_preparation', 'confirmed', 'received'], true)) {
                    $grouped[$orderKey]['validation_errors'][] = "Unrecognized PO status: '{$rawStatus}'.";
                }
            } else {
                // Validate header consistency across rows of the same PO
                $currentSupplier = trim((string) ($mapped['supplier'] ?? ''));
                if ($currentSupplier !== '' && strcasecmp($currentSupplier, $grouped[$orderKey]['supplier']) !== 0) {
                    $grouped[$orderKey]['validation_errors'][] = "Supplier mismatch in PO {$poNumber}: '{$currentSupplier}' vs '{$grouped[$orderKey]['supplier']}'.";
                }

                $currentDate = trim((string) ($mapped['date'] ?? ''));
                if ($currentDate !== '' && $grouped[$orderKey]['po_date'] !== null && $currentDate !== $grouped[$orderKey]['po_date']) {
                    $grouped[$orderKey]['validation_errors'][] = "Date mismatch in PO {$poNumber}: '{$currentDate}' vs '{$grouped[$orderKey]['po_date']}'.";
                }
            }

            $code = trim((string) ($mapped['code'] ?? ''));
            $name = trim((string) ($mapped['name'] ?? ''));
            $qty = $this->cleanAmount($mapped['amount'] ?? null);
            $price = $this->cleanAmount($mapped['unit_price'] ?? null);
            $rawUnit = trim((string) ($mapped['unit'] ?? ''));
            $unit = $rawUnit !== '' ? $rawUnit : null;

            $isAdjustment = $this->isFinancialAdjustment($code, $name, $qty, $price);

            // Compute line total if possible or use clean amount from mapped line_total
            $lineTotal = $this->cleanAmount($mapped['line_total'] ?? null);
            if ($lineTotal === null && $qty !== null && $price !== null) {
                $qtyVal = (float) $qty;
                $priceVal = (float) $price;
                $lineTotal = number_format($qtyVal * $priceVal, 4, '.', '');
            }

            $lineIndex = count($grouped[$orderKey]['items']) + 1;
            $grouped[$orderKey]['items'][] = [
                'sort_order' => $lineIndex,
                'source_line_id' => "po:{$normalizedPoNumber}:line:{$lineIndex}",
                'item_code' => $code !== '' ? $code : null,
                'product_description' => $name !== '' ? $name : null,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_price' => $price,
                'line_total' => $lineTotal,
                'is_financial_adjustment' => $isAdjustment,
            ];
        }

        // Compute row hash for each order for change tracking
        foreach ($grouped as $key => $order) {
            $grouped[$key]['row_hash'] = hash('sha256', json_encode([
                'po' => $order['po_number'],
                'supplier' => $order['supplier'],
                'status' => $order['raw_status'],
                'date' => $order['po_date_value'],
                'total' => $order['total_amount'],
                'items' => $order['items'],
            ]));
        }

        return [
            'orders' => $grouped,
            'invalid_rows' => $invalidRows,
        ];
    }

    public function normalizeStatus(string $rawStatus): string
    {
        $clean = Str::lower(trim($rawStatus));

        return match (true) {
            str_contains($clean, 'preparation') || str_contains($clean, 'draft') => 'in_preparation',
            str_contains($clean, 'confirmed') || str_contains($clean, 'approved') => 'confirmed',
            str_contains($clean, 'received') || str_contains($clean, 'delivered') || str_contains($clean, 'completed') => 'received',
            default => 'unknown',
        };
    }

    public function isFinancialAdjustment(string $code, string $name, ?string $qty, ?string $price): bool
    {
        $haystack = Str::lower("{$code} {$name}");

        if (Str::contains($haystack, ['discount', 'rebate', 'adjustment', 'freight', 'delivery fee', 'credit', 'voucher'])) {
            return true;
        }

        if ($price !== null && (float) $price < 0) {
            return true;
        }

        return false;
    }

    private function cleanAmount(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }

        $str = trim((string) $val);
        if ($str === '') {
            return null;
        }

        // Remove currency symbols, commas, whitespace
        $cleaned = preg_replace('/[^\d.-]/', '', $str) ?? '';

        return $cleaned !== '' && is_numeric($cleaned) ? $cleaned : null;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function ensureAssociativeRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $first = reset($rows);

        // If rows are 0-indexed arrays, assume row 0 contains headers
        if (is_array($first) && array_keys($first) === range(0, count($first) - 1)) {
            $headers = array_map(fn ($h) => trim((string) $h), $first);
            $assoc = [];
            $len = count($rows);
            for ($i = 1; $i < $len; $i++) {
                $row = $rows[$i];
                if (! is_array($row)) {
                    continue;
                }
                $item = [];
                foreach ($headers as $colIdx => $header) {
                    $item[$header] = $row[$colIdx] ?? null;
                }
                $assoc[] = $item;
            }

            return $assoc;
        }

        return $rows;
    }

    /**
     * Map varying column names to normalized field keys.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $lookup = [];
        foreach ($row as $k => $v) {
            $normalizedKey = Str::lower(preg_replace('/[^a-z0-9]+/i', '', (string) $k) ?? '');
            $lookup[$normalizedKey] = $v;
        }

        return [
            'supplier' => $this->firstOf($lookup, ['supplier', 'vendor', 'suppliername', 'vendorname']),
            'type' => $this->firstOf($lookup, ['type', 'documenttype', 'doctype']),
            'status' => $this->firstOf($lookup, ['status', 'postatus']),
            'po_number' => $this->firstOf($lookup, ['pono', 'ponumber', 'purchaseorderno', 'purchaseordernumber', 'po']),
            'date' => $this->firstOf($lookup, ['date', 'podate', 'orderdate']),
            'net_total' => $this->firstOf($lookup, ['nettotal', 'subtotal', 'net']),
            'vat_total' => $this->firstOf($lookup, ['vattotal', 'vat', 'tax']),
            'total_sum' => $this->firstOf($lookup, ['totalsum', 'totalamount', 'total', 'grandtotal']),
            'notes' => $this->firstOf($lookup, ['notes', 'remarks', 'comment', 'comments']),
            'code' => $this->firstOf($lookup, ['code', 'itemcode', 'sku', 'skunumber', 'partno']),
            'name' => $this->firstOf($lookup, ['name', 'description', 'itemdescription', 'productname', 'item']),
            'amount' => $this->firstOf($lookup, ['amount', 'quantity', 'qty', 'orderedqty', 'orderqty']),
            'unit' => $this->firstOf($lookup, ['unit', 'uom', 'package', 'pack']),
            'unit_price' => $this->firstOf($lookup, ['unitprice', 'price', 'rate']),
            'line_total' => $this->firstOf($lookup, ['linetotal', 'itemtotal', 'totalamount', 'total']),
        ];
    }

    private function firstOf(array $lookup, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (isset($lookup[$k]) && $lookup[$k] !== '') {
                return $lookup[$k];
            }
        }

        return null;
    }
}
