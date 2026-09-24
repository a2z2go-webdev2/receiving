<?php

namespace App\Enums;

enum ReceiptPostingStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Failed = 'failed';
    case Exempt = 'exempt';
}
