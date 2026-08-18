<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\UploadType;
use App\Services\LegacyImport\Contracts\LegacyDataImporterInterface;

class StandardLegacyImporter implements LegacyDataImporterInterface
{
    public function __construct(
        private readonly PingconLegacyImporter $pingconImporter
    ) {}

    public function supports(UploadType $uploadType): bool
    {
        return true; // Fallback for any upload type
    }

    public function import(
        UploadType $uploadType,
        array $parsedLogs,
        array $parsedFiles,
        array $parsedExtractions,
        array $options = []
    ): array {
        // Delegate to PingconLegacyImporter standard workflow processing
        return $this->pingconImporter->import(
            $uploadType,
            $parsedLogs,
            $parsedFiles,
            $parsedExtractions,
            $options
        );
    }
}
