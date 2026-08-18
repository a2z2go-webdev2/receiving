<?php

namespace App\Enums;

enum UploadProcessingStatus: string
{
    case Staging = 'staging';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartialFailed = 'partial_failed';
    case Failed = 'failed';
}
