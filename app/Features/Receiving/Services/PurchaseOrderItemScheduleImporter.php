<?php

namespace App\Features\Receiving\Services;

use App\Models\PurchaseOrderItemSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

/**
 * @phpstan-type StatsArray array{rows: int, records: int, created: int, updated: int, deactivated: int, skipped: int}
 */
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

    public const NEW_WITH_CATEGORY_COLUMNS = [
        'Serial Number',
        'SKU',
        'EAN',
        'Description',
        'Sold Qty',
        'Package',
        'Package Unit',
        'Target Quantity',
        'Main Unit',
        'Category',
    ];

    public const FOOD_MD_REQUIRED_COLUMNS = [
        'SN',
        'Code',
        'EAN',
        'SKU',
        'Unit',
    ];

    public const NON_FOOD_MD_REQUIRED_COLUMNS = [
        'SN',
        'Code',
        'EAN',
        'SKU',
        'Quantity of Package Contains',
        'Unit of Quantity of Package Contains',
        'Target Quantity',
        'Package',
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
        if (is_dir($path)) {
            return $this->importDirectory($path, $creator, $deactivateMissing);
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("PO item schedule file is not readable: {$path}");
        }

        return DB::transaction(function () use ($path, $creator, $deactivateMissing): array {
            $stats = [
                'rows' => 0,
                'records' => 0,
                'created' => 0,
                'updated' => 0,
                'deactivated' => 0,
                'skipped' => 0,
            ];
            $sourceKeys = [];

            $this->importFile($path, $creator, $stats, $sourceKeys);

            if ($deactivateMissing) {
                $stats['deactivated'] = PurchaseOrderItemSchedule::query()
                    ->whereIn('source', [self::SOURCE, self::LEGACY_SOURCE])
                    ->when($sourceKeys !== [], fn ($query) => $query->whereNotIn('source_key', $sourceKeys))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            return $stats;
        });
    }

    /** @return array{rows: int, records: int, created: int, updated: int, deactivated: int, skipped: int} */
    public function importDirectory(string $directoryPath, ?User $creator = null, bool $deactivateMissing = true): array
    {
        if (! is_dir($directoryPath) || ! is_readable($directoryPath)) {
            throw new RuntimeException("PO item schedule directory is not readable: {$directoryPath}");
        }

        $files = collect(scandir($directoryPath) ?: [])
            ->filter(fn (string $file): bool => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['md', 'csv'], true))
            ->sort()
            ->map(fn (string $file): string => rtrim($directoryPath, '/\\').DIRECTORY_SEPARATOR.$file)
            ->values();

        if ($files->isEmpty()) {
            throw new RuntimeException("No .md or .csv files found in PO item directory: {$directoryPath}");
        }

        return DB::transaction(function () use ($files, $creator, $deactivateMissing): array {
            $stats = [
                'rows' => 0,
                'records' => 0,
                'created' => 0,
                'updated' => 0,
                'deactivated' => 0,
                'skipped' => 0,
            ];
            $sourceKeys = [];

            foreach ($files as $filePath) {
                $this->importFile($filePath, $creator, $stats, $sourceKeys);
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

    /**
     * @param  StatsArray  $stats
     *
     * @param-out StatsArray $stats
     *
     * @param  array<int, string>  $sourceKeys
     *
     * @param-out array<int, string> $sourceKeys
     */
    private function importFile(string $filePath, ?User $creator, array &$stats, array &$sourceKeys): void
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'md') {
            $this->importMarkdownFile($filePath, $creator, $stats, $sourceKeys);

            return;
        }

        $this->importCsvFile($filePath, $creator, $stats, $sourceKeys);
    }

    /**
     * @param  StatsArray  $stats
     *
     * @param-out StatsArray $stats
     *
     * @param  array<int, string>  $sourceKeys
     *
     * @param-out array<int, string> $sourceKeys
     */
    private function importCsvFile(string $filePath, ?User $creator, array &$stats, array &$sourceKeys): void
    {
        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $header = $file->fgetcsv();
        if (! is_array($header)) {
            throw new RuntimeException("PO item schedule CSV is empty: {$filePath}");
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $format = $this->detectHeaderFormat($header);

        while (! $file->eof()) {
            $line = $file->fgetcsv();
            if (! is_array($line) || $this->blankLine($line)) {
                continue;
            }

            $stats['rows']++;
            $row = array_combine($header, array_pad($line, count($header), ''));

            if ($format === 'new' || $format === 'new_with_category') {
                $this->importNewRow($row, $creator, $stats, $sourceKeys);
            } else {
                $this->importLegacyRow($row, $creator, $stats, $sourceKeys);
            }
        }
    }

    /**
     * @param  StatsArray  $stats
     *
     * @param-out StatsArray $stats
     *
     * @param  array<int, string>  $sourceKeys
     *
     * @param-out array<int, string> $sourceKeys
     */
    private function importMarkdownFile(string $filePath, ?User $creator, array &$stats, array &$sourceKeys): void
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            throw new RuntimeException("PO item schedule Markdown file is empty: {$filePath}");
        }

        $header = null;
        $format = null;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if (! str_starts_with($line, '|')) {
                continue;
            }

            $cols = array_map('trim', explode('|', trim($line, '|')));

            if ($header === null) {
                $header = $cols;
                $format = $this->detectMarkdownHeaderFormat($header);

                continue;
            }

            if (str_contains($cols[0] ?? '', '---')) {
                continue;
            }

            if (count($cols) < count($header)) {
                $cols = array_pad($cols, count($header), '');
            }

            $stats['rows']++;
            $row = array_combine(array_slice($header, 0, count($cols)), array_slice($cols, 0, count($header)));

            if ($format === 'food') {
                $this->importMdFoodRow($row, $creator, $stats, $sourceKeys);
            } elseif ($format === 'non_food') {
                $this->importMdNonFoodRow($row, $creator, $stats, $sourceKeys);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  StatsArray  $stats
     *
     * @param-out StatsArray $stats
     *
     * @param  array<int, string>  $sourceKeys
     *
     * @param-out array<int, string> $sourceKeys
     */
    private function importMdFoodRow(array $row, ?User $creator, array &$stats, array &$sourceKeys): void
    {
        $code = trim((string) ($row['Code'] ?? ''));
        $description = trim((string) ($row['SKU'] ?? ''));

        // Skip non-item rows such as dividers or discount entries
        if ($code === '' || $code === 'Discount' || $description === '- - - - - -' || $description === 'Partner Discount') {
            $stats['skipped']++;

            return;
        }

        $descriptionNormalized = $this->normalizer->normalizeDescription($description);
        if ($description === '' || $descriptionNormalized === null) {
            $stats['skipped']++;

            return;
        }

        $serialNumber = is_numeric($row['SN'] ?? '') ? (int) $row['SN'] : null;
        $skuNormalized = $this->normalizer->normalizeIdentifier($code);

        $ean = trim((string) ($row['EAN'] ?? ''));
        if ($ean === '—' || $ean === '###') {
            $ean = '';
        }
        $eanNormalized = $this->normalizer->normalizeIdentifier($ean);

        $unit = trim((string) ($row['Unit'] ?? ''));
        if ($unit === 'x1' || $unit === '') {
            $unit = 'pc';
        }

        $sourceKey = $this->sourceKeyNew($serialNumber, $skuNormalized, $descriptionNormalized);
        $sourceKeys[] = $sourceKey;

        $schedule = PurchaseOrderItemSchedule::query()->updateOrCreate(
            [
                'source' => self::SOURCE,
                'source_key' => $sourceKey,
            ],
            [
                'serial_number' => $serialNumber,
                'sku_number' => $code,
                'sku_number_normalized' => $skuNormalized,
                'ean_barcode' => $ean === '' ? null : $ean,
                'ean_barcode_normalized' => $eanNormalized,
                'description' => $description,
                'description_normalized' => $descriptionNormalized,
                'target_quantity' => null,
                'package_quantity' => null,
                'package_unit' => null,
                'sold_quantity' => null,
                'unit' => $unit,
                'category' => 'food',
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

    /**
     * @param  array<string, mixed>  $row
     * @param  StatsArray  $stats
     *
     * @param-out StatsArray $stats
     *
     * @param  array<int, string>  $sourceKeys
     *
     * @param-out array<int, string> $sourceKeys
     */
    private function importMdNonFoodRow(array $row, ?User $creator, array &$stats, array &$sourceKeys): void
    {
        $code = trim((string) ($row['Code'] ?? ''));
        $description = trim((string) ($row['SKU'] ?? ''));

        if ($code === '' || $description === '') {
            $stats['skipped']++;

            return;
        }

        $descriptionNormalized = $this->normalizer->normalizeDescription($description);
        if ($descriptionNormalized === null) {
            $stats['skipped']++;

            return;
        }

        $serialNumber = is_numeric($row['SN'] ?? '') ? (int) $row['SN'] : null;
        $skuNormalized = $this->normalizer->normalizeIdentifier($code);

        $ean = trim((string) ($row['EAN'] ?? ''));
        if ($ean === '—' || $ean === '###') {
            $ean = '';
        }
        $eanNormalized = $this->normalizer->normalizeIdentifier($ean);

        $packageQty = is_numeric($row['Quantity of Package Contains'] ?? '') ? (float) $row['Quantity of Package Contains'] : null;
        $packageUnit = trim((string) ($row['Unit of Quantity of Package Contains'] ?? '')) ?: null;
        $targetQty = is_numeric($row['Target Quantity'] ?? '') ? (float) $row['Target Quantity'] : null;
        $unit = trim((string) ($row['Package'] ?? '')) ?: null;

        $sourceKey = $this->sourceKeyNew($serialNumber, $skuNormalized, $descriptionNormalized);
        $sourceKeys[] = $sourceKey;

        $schedule = PurchaseOrderItemSchedule::query()->updateOrCreate(
            [
                'source' => self::SOURCE,
                'source_key' => $sourceKey,
            ],
            [
                'serial_number' => $serialNumber,
                'sku_number' => $code,
                'sku_number_normalized' => $skuNormalized,
                'ean_barcode' => $ean === '' ? null : $ean,
                'ean_barcode_normalized' => $eanNormalized,
                'description' => $description,
                'description_normalized' => $descriptionNormalized,
                'target_quantity' => $targetQty !== null ? $this->normalizer->decimalString($targetQty) : null,
                'package_quantity' => $packageQty !== null ? $this->normalizer->decimalString($packageQty) : null,
                'package_unit' => $packageUnit,
                'sold_quantity' => null,
                'unit' => $unit,
                'category' => 'non_food',
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

    /**
     * @param  array<string, mixed>  $row
     * @param  StatsArray  $stats
     *
     * @param-out StatsArray $stats
     *
     * @param  array<int, string>  $sourceKeys
     *
     * @param-out array<int, string> $sourceKeys
     */
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
        if ($ean === '—' || $ean === '###') {
            $ean = '';
        }
        $eanNormalized = $this->normalizer->normalizeIdentifier($ean);
        $unit = trim((string) ($row['Main Unit'] ?? '')) ?: null;

        $targetRaw = trim((string) ($row['Target Quantity'] ?? ''));
        $targetQty = ($targetRaw !== '' && is_numeric($targetRaw)) ? $this->quantity($targetRaw) : null;
        $packageQty = is_numeric($row['Package'] ?? '') ? (float) $row['Package'] : null;
        $packageUnit = trim((string) ($row['Package Unit'] ?? '')) ?: null;
        $soldQty = is_numeric($row['Sold Qty'] ?? '') ? (float) $row['Sold Qty'] : null;

        $category = strtolower(trim((string) ($row['Category'] ?? '')));
        if ($category === '') {
            $category = ($targetQty === null && $packageQty === null) ? 'food' : 'non_food';
        }

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
                'ean_barcode' => $ean === '' ? null : $ean,
                'ean_barcode_normalized' => $eanNormalized,
                'description' => $description,
                'description_normalized' => $descriptionNormalized,
                'target_quantity' => $targetQty !== null ? $this->normalizer->decimalString($targetQty) : null,
                'package_quantity' => $packageQty !== null ? $this->normalizer->decimalString($packageQty) : null,
                'package_unit' => $packageUnit,
                'sold_quantity' => $soldQty !== null ? $this->normalizer->decimalString($soldQty) : null,
                'unit' => $unit,
                'category' => $category,
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

    /**
     * @param  array<string, mixed>  $row
     * @param  StatsArray  $stats
     *
     * @param-out StatsArray $stats
     *
     * @param  array<int, string>  $sourceKeys
     *
     * @param-out array<int, string> $sourceKeys
     */
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
                    'category' => 'non_food',
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
        if ($actual === self::NEW_WITH_CATEGORY_COLUMNS) {
            return 'new_with_category';
        }
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

    /** @param array<int, mixed> $header */
    private function detectMarkdownHeaderFormat(array $header): string
    {
        $actual = array_map(fn (mixed $value): string => trim((string) $value), $header);
        if ($actual === self::FOOD_MD_REQUIRED_COLUMNS) {
            return 'food';
        }
        if ($actual === self::NON_FOOD_MD_REQUIRED_COLUMNS) {
            return 'non_food';
        }

        throw new RuntimeException(
            'PO item schedule Markdown header is invalid. Found: '.implode(', ', $actual),
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
