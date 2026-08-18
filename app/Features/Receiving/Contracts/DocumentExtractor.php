<?php

namespace App\Features\Receiving\Contracts;

use App\Enums\UploadWorkflow;
use Carbon\CarbonInterface;

interface DocumentExtractor
{
    /** @return array<string, mixed> */
    public function extract(
        string $absolutePath,
        string $mimeType,
        UploadWorkflow $workflow = UploadWorkflow::Standard,
        CarbonInterface|string|null $uploadDate = null,
    ): array;
}
