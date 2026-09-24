<?php

namespace App\Enums;

enum PurchaseOrderSourceStatus: string
{
    case InPreparation = 'in_preparation';
    case Confirmed = 'confirmed';
    case Received = 'received';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isEligible(): bool
    {
        return in_array($this, [self::Confirmed, self::Received], true);
    }
}
