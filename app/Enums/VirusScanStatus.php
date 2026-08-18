<?php

namespace App\Enums;

enum VirusScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Suspicious = 'suspicious';
    case Failed = 'failed';
}
