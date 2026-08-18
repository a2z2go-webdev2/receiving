<?php

namespace App\Services\LegacyImport\Contracts;

use App\Models\UploadType;

interface LegacyDataImporterInterface
{
    public function supports(UploadType $uploadType): bool;

    /**
     * @param  array<int, array<string, mixed>>  $parsedLogs
     * @param  array<int, array<string, mixed>>  $parsedFiles
     * @param  array<int, array<string, mixed>>  $parsedExtractions
     * @param  array<string, mixed>  $options
     * @return array{imported_submissions: int, imported_files: int, imported_extractions: int, skipped: int, errors: array<string>}
     */
    public function import(
        UploadType $uploadType,
        array $parsedLogs,
        array $parsedFiles,
        array $parsedExtractions,
        array $options = []
    ): array;
}
