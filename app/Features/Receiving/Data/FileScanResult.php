<?php

namespace App\Features\Receiving\Data;

use App\Enums\VirusScanStatus;

readonly class FileScanResult
{
    public function __construct(
        public VirusScanStatus $status,
        public ?string $message = null,
    ) {}
}
