<?php

namespace App\Enums;

enum PurchaseOrderArrivalStatus: string
{
    case Pending = 'pending';
    case Arrived = 'arrived';
    case MissingPoNumber = 'missing_po_number';
}
