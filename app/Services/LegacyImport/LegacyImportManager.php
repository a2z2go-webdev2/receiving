<?php

namespace App\Services\LegacyImport;

use App\Models\UploadType;
use App\Services\LegacyImport\Contracts\LegacyDataImporterInterface;
use App\Services\LegacyImport\Importers\BonitaLegacyImporter;
use App\Services\LegacyImport\Importers\PingconLegacyImporter;
use App\Services\LegacyImport\Importers\StandardLegacyImporter;
use App\Services\LegacyImport\Parsers\CsvTableParser;
use App\Services\LegacyImport\Parsers\HtmlTableParser;
use InvalidArgumentException;

class LegacyImportManager
{
    /** @var array<int, LegacyDataImporterInterface> */
    private array $importers;

    public function __construct(
        private readonly CsvTableParser $csvParser,
        private readonly HtmlTableParser $htmlParser,
        PingconLegacyImporter $pingconImporter,
        BonitaLegacyImporter $bonitaImporter,
        StandardLegacyImporter $standardImporter
    ) {
        $this->importers = [
            $pingconImporter,
            $bonitaImporter,
            $standardImporter,
        ];
    }

    /**
     * Resolve dedicated importer for the given UploadType.
     */
    public function resolveImporter(UploadType $uploadType): LegacyDataImporterInterface
    {
        foreach ($this->importers as $importer) {
            if ($importer->supports($uploadType)) {
                return $importer;
            }
        }

        throw new InvalidArgumentException("No importer registered for upload type {$uploadType->slug}");
    }

    /**
     * Parse raw string content or file paths into normalized tables and execute import.
     *
     * @param  array{logs?: string, files?: string, extractions?: string}  $inputs
     * @param  array<string, mixed>  $options
     * @return array{imported_submissions: int, imported_files: int, imported_extractions: int, skipped: int, errors: array<string>}
     */
    public function importFromInputs(UploadType $uploadType, array $inputs, array $options = []): array
    {
        $parsedLogs = $this->parseInput($inputs['logs'] ?? '');
        $parsedFiles = $this->parseInput($inputs['files'] ?? '');
        $parsedExtractions = $this->parseInput($inputs['extractions'] ?? '');

        $importer = $this->resolveImporter($uploadType);

        return $importer->import($uploadType, $parsedLogs, $parsedFiles, $parsedExtractions, $options);
    }

    /**
     * Automatically detect format (CSV or HTML) and parse table data.
     *
     * @return array<int, array<string, string>>
     */
    public function parseInput(string $contentOrPath): array
    {
        if (trim($contentOrPath) === '') {
            return [];
        }

        $isHtml = str_contains(strtolower($contentOrPath), '<html')
            || str_contains(strtolower($contentOrPath), '<table')
            || (file_exists($contentOrPath) && strtolower(pathinfo($contentOrPath, PATHINFO_EXTENSION)) === 'html');

        if ($isHtml) {
            return $this->htmlParser->parse($contentOrPath);
        }

        return $this->csvParser->parse($contentOrPath);
    }
}
