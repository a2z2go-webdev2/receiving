<?php

namespace App\Features\Receiving\Services;

use App\Enums\VirusScanStatus;
use App\Features\Receiving\Contracts\FileScanner;
use App\Features\Receiving\Data\FileScanResult;

class TestingCleanFileScanner implements FileScanner
{
    public function scan(string $absolutePath): FileScanResult
    {
        return new FileScanResult(VirusScanStatus::Clean);
    }
}
