<?php

namespace App\Enums;

enum PurchaseOrderLinkStatus: string
{
    case NotApplicable = 'not_applicable';
    case MissingPoNumber = 'missing_po_number';
    case AwaitingPurchaseOrder = 'awaiting_purchase_order';
    case ReadyToLink = 'ready_to_link';
    case PurchaseOrderAlreadyLinked = 'purchase_order_already_linked';
    case Linked = 'linked';
}
