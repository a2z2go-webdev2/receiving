<?php

namespace App\Enums;

enum WarehouseDateQuality: string
{
    case Confirmed = 'confirmed';
    case Estimated = 'estimated';
    case Unknown = 'unknown';
}
