<?php

namespace App\Enums;

enum AiStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Extracted = 'extracted';
    case PartialFailed = 'partial_failed';
    case Failed = 'failed';
    case ManualReview = 'manual_review';
}
