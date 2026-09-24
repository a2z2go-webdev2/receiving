<?php

namespace App\Enums;

enum ReceivingSourceMode: string
{
    case Parallel = 'parallel';
    case Reconciliation = 'reconciliation';
    case AppOnly = 'app_only';
}
