<?php

namespace App\Enums;

enum PurchaseOrderLinkSource: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
