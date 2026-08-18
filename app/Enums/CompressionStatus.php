<?php

namespace App\Enums;

enum CompressionStatus: string
{
    case Pending = 'pending';
    case Compressed = 'compressed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
