<?php

namespace App\Enums;

enum EmailStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case NotRequired = 'not_required';
}
