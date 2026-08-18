<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case Revision = 'revision';
    case Verified = 'verified';
    case NotRequired = 'not_required';
}
