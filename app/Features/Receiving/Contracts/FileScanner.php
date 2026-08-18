<?php

namespace App\Features\Receiving\Contracts;

use App\Features\Receiving\Data\FileScanResult;

interface FileScanner
{
    public function scan(string $absolutePath): FileScanResult;
}
