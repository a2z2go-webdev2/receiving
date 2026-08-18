<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\UploadType;
use App\Services\LegacyImport\Contracts\LegacyDataImporterInterface;

class BonitaLegacyImporter implements LegacyDataImporterInterface
{
    public function __construct(
        private readonly PingconLegacyImporter $pingconImporter
    ) {}

    public function supports(UploadType $uploadType): bool
    {
        return strtolower($uploadType->slug) === 'bonita';
    }

    public function import(
        UploadType $uploadType,
        array $parsedLogs,
        array $parsedFiles,
        array $parsedExtractions,
        array $options = []
    ): array {
        return $this->pingconImporter->import(
            $uploadType,
            $parsedLogs,
            $parsedFiles,
            $parsedExtractions,
            $options
        );
    }
}
