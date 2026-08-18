<?php

namespace App\Enums;

enum WarehouseStockSource: string
{
    case Arrival = 'arrival';
    case OpeningBalance = 'opening_balance';
}
