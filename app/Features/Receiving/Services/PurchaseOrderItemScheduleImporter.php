<?php

namespace App\Features\Receiving\Services;

use App\Models\PurchaseOrderItemSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class PurchaseOrderItemScheduleImporter
{
    public const SOURCE = 'po_item_records';

    public const LEGACY_SOURCE = 'po_master_list_cleaned';

    public const NEW_REQUIRED_COLUMNS = [
        'Serial Number',
        'SKU',
        'EAN',
        'Description',
        'Sold Qty',
        'Package',
        'Package Unit',
        'Target Quantity',
        'Main Unit',
    ];

    public const LEGACY_REQUIRED_COLUMNS = [
        'SKU',
        'Description',
        'Unit',
        'Week 1 Qty',
        'Week 2 Qty',
        'Week 3 Qty',
        'Week 4 Qty',
        'Total Qty',
    ];

    public function __construct(private readonly PurchaseOrderDataNormalizer $normalizer) {}

    /** @return array{rows: int, records: int, created: int, updated: int, deactivated: int, skipped: int} */
    public function import(string $path, ?User $creator = null, bool $deactivateMissing = true): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("PO item schedule CSV is not readable: {$path}");
        }

        return DB::transaction(function () use ($path, $creator, $deactivateMissing): array {
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

            $header = $file->fgetcsv();
            if (! is_array($header)) {
                throw new RuntimeException('PO item schedule CSV is empty.');
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $format = $this->detectHeaderFormat($header);

            $stats = [
                'rows' => 0,
                'records' => 0,
                'created' => 0,
                'updated' => 0,
                'deactivated' => 0,
                'skipped' => 0,
            ];
            $sourceKeys = [];

            while (! $file->eof()) {
                $line = $file->fgetcsv();
                if (! is_array($line) || $this->blankLine($line)) {
                    continue;
                }

                $stats['rows']++;
                $row = array_combine($header, array_pad($line, count($header), ''));

                if ($format === 'new') {
                    $this->importNewRow($row, $creator, $stats, $sourceKeys);
                } else {
                    $this->importLegacyRow($row, $creator, $stats, $sourceKeys);
                }
            }

            if ($deactivateMissing) {
                $stats['deactivated'] = PurchaseOrderItemSchedule::query()
                    ->whereIn('source', [self::SOURCE, self::LEGACY_SOURCE])
                    ->when($sourceKeys !== [], fn ($query) => $query->whereNotIn('source_key', $sourceKeys))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            return $stats;
        });
    }

    /** @param array<string, mixed> $row @param array<int, string> $sourceKeys */
    private function importNewRow(array $row, ?User $creator, array &$stats, array &$sourceKeys): void
    {
        $description = trim((string) $row['Description']);
        $descriptionNormalized = $this->normalizer->normalizeDescription($description);
        if ($description === '' || $descriptionNormalized === null) {
            $stats['skipped']++;

            return;
        }

        $serialNumber = is_numeric($row['Serial Number'] ?? '') ? (int) $row['Serial Number'] : null;
        $sku = trim((string) $row['SKU']);
        $skuNormalized = $this->normalizer->normalizeIdentifier($sku);
        $ean = trim((string) ($row['EAN'] ?? ''));
        $eanNormalized = $this->normalizer->normalizeIdentifier($ean);
        $unit = trim((string) ($row['Main Unit'] ?? '')) ?: null;
        $targetQty = $this->quantity($row['Target Quantity'] ?? '0');
        $packageQty = is_numeric($row['Package'] ?? '') ? (float) $row['Package'] : null;
        $packageUnit = trim((string) ($row['Package Unit'] ?? '')) ?: null;
        $soldQty = is_numeric($row['Sold Qty'] ?? '') ? (float) $row['Sold Qty'] : null;

        $sourceKey = $this->sourceKeyNew($serialNumber, $skuNormalized, $descriptionNormalized);
        $sourceKeys[] = $sourceKey;

        $schedule = PurchaseOrderItemSchedule::query()->updateOrCreate(
            [
                'source' => self::SOURCE,
                'source_key' => $sourceKey,
            ],
            [
                'serial_number' => $serialNumber,
                'sku_number' => $sku === '' ? null : $sku,
                'sku_number_normalized' => $skuNormalized,
                'ean_barcode' => $ean === '' || $ean === '###' ? null : $ean,
                'ean_barcode_normalized' => $eanNormalized,
                'description' => $description,
                'description_normalized' => $descriptionNormalized,
                'target_quantity' => $this->normalizer->decimalString($targetQty),
                'package_quantity' => $packageQty !== null ? $this->normalizer->decimalString($packageQty) : null,
                'package_unit' => $packageUnit,
                'sold_quantity' => $soldQty !== null ? $this->normalizer->decimalString($soldQty) : null,
                'unit' => $unit,
                'expected_week' => null,
                'is_special_order' => false,
                'is_active' => true,
                'notes' => null,
                'created_by' => $creator?->getKey(),
            ],
        );

        $stats[$schedule->wasRecentlyCreated ? 'created' : 'updated']++;
        $stats['records']++;
    }

    /** @param array<string, mixed> $row @param array<int, string> $sourceKeys */
    private function importLegacyRow(array $row, ?User $creator, array &$stats, array &$sourceKeys): void
    {
        $description = trim((string) $row['Description']);
        $descriptionNormalized = $this->normalizer->normalizeDescription($description);
        if ($description === '' || $descriptionNormalized === null) {
            $stats['skipped']++;

            return;
        }

        $sku = trim((string) $row['SKU']);
        $skuNormalized = $this->normalizer->normalizeIdentifier($sku);
        $unit = trim((string) $row['Unit']) ?: null;
        $weeklyTotal = 0.0;

        for ($week = 1; $week <= 4; $week++) {
            $quantity = $this->quantity($row["Week {$week} Qty"] ?? '0');
            $weeklyTotal += $quantity;
            if ($quantity <= 0.0) {
                continue;
            }

            $sourceKey = $this->sourceKeyLegacy($skuNormalized, $descriptionNormalized, $week);
            $sourceKeys[] = $sourceKey;

            $schedule = PurchaseOrderItemSchedule::query()->updateOrCreate(
                [
                    'source' => self::LEGACY_SOURCE,
                    'source_key' => $sourceKey,
                ],
                [
                    'sku_number' => $sku === '' ? null : $sku,
                    'sku_number_normalized' => $skuNormalized,
                    'description' => $description,
                    'description_normalized' => $descriptionNormalized,
                    'target_quantity' => $this->normalizer->decimalString($quantity),
                    'unit' => $unit,
                    'expected_week' => $week,
                    'is_special_order' => false,
                    'is_active' => true,
                    'notes' => null,
                    'created_by' => $creator?->getKey(),
                ],
            );

            $stats[$schedule->wasRecentlyCreated ? 'created' : 'updated']++;
            $stats['records']++;
        }

        $total = $this->quantity($row['Total Qty'] ?? '0');
        if (abs($weeklyTotal - $total) > 0.0001) {
            throw new RuntimeException(
                "PO item CSV total mismatch for {$description}: weeks={$weeklyTotal}, total={$total}.",
            );
        }
    }

    /** @param array<int, mixed> $header */
    private function detectHeaderFormat(array $header): string
    {
        $actual = array_map(fn (mixed $value): string => trim((string) $value), $header);
        if ($actual === self::NEW_REQUIRED_COLUMNS) {
            return 'new';
        }
        if ($actual === self::LEGACY_REQUIRED_COLUMNS) {
            return 'legacy';
        }

        throw new RuntimeException(
            'PO item schedule CSV header is invalid. Expected: '.implode(', ', self::NEW_REQUIRED_COLUMNS),
        );
    }

    /** @param array<int, mixed> $line */
    private function blankLine(array $line): bool
    {
        return collect($line)->every(fn (mixed $value): bool => trim((string) $value) === '');
    }

    private function quantity(mixed $value): float
    {
        return $this->normalizer->quantity((string) $value);
    }

    private function sourceKeyNew(?int $serial, ?string $skuNormalized, string $descriptionNormalized): string
    {
        return sprintf(
            'item:%s:%s:%s',
            $serial ?? 'no-sn',
            $skuNormalized ?: 'no-sku',
            substr(hash('sha256', $descriptionNormalized), 0, 32),
        );
    }

    private function sourceKeyLegacy(?string $skuNormalized, string $descriptionNormalized, int $week): string
    {
        return sprintf(
            'w%d:%s:%s',
            $week,
            $skuNormalized ?: 'no-sku',
            substr(hash('sha256', $descriptionNormalized), 0, 32),
        );
    }
}
