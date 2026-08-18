<?php

namespace App\Enums;

enum WarehouseDeliveryStatus: string
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
}
