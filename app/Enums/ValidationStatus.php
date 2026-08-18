<?php

namespace App\Enums;

enum ValidationStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Failed = 'failed';
}
