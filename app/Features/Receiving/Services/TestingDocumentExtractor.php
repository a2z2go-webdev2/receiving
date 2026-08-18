<?php

namespace App\Features\Receiving\Services;

use App\Enums\UploadWorkflow;
use App\Features\Receiving\Contracts\DocumentExtractor;
use Carbon\CarbonInterface;

class TestingDocumentExtractor implements DocumentExtractor
{
    public function extract(
        string $absolutePath,
        string $mimeType,
        UploadWorkflow $workflow = UploadWorkflow::Standard,
        CarbonInterface|string|null $uploadDate = null,
    ): array {
        return [
            'document_type' => 'Other',
            'fields' => [
                ['label' => 'Summary', 'value' => 'Deterministic testing extraction.'],
            ],
            'items' => [],
        ];
    }
}
