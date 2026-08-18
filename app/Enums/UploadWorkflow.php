<?php

namespace App\Enums;

enum UploadWorkflow: string
{
    case Standard = 'standard';
    case PurchaseOrder = 'purchase_order';

    public function sendsNotifications(): bool
    {
        return $this === self::Standard;
    }

    public function requiresReview(): bool
    {
        return $this === self::Standard;
    }

    public function serialPrefix(): string
    {
        return $this === self::PurchaseOrder ? 'POSN' : 'SN';
    }
}
