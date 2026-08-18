<?php

namespace App\Models;

use App\Enums\PurchaseOrderArrivalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $ai_extraction_id
 * @property int $receiving_upload_id
 * @property string|null $po_number
 * @property string|null $po_number_normalized
 * @property string|null $po_reference
 * @property string|null $po_date
 * @property CarbonImmutable|null $po_date_value
 * @property PurchaseOrderArrivalStatus $arrival_status
 * @property string|null $buyer_company
 * @property string|null $buyer_address
 * @property string|null $buyer_contact_numbers
 * @property string|null $vendor_name
 * @property string|null $contact_person
 * @property string|null $vendor_email
 * @property string|null $vendor_mobile
 * @property string|null $vendor_address
 * @property string|null $payment_terms
 * @property string|null $subtotal
 * @property string|null $vat
 * @property string|null $total_amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AiExtraction $aiExtraction
 * @property-read ReceivingUpload $upload
 * @property-read Collection<int, PoExtractionItem> $items
 * @property-read Collection<int, PurchaseOrderItemFulfillment> $fulfillments
 * @property-read Collection<int, PurchaseOrderDocumentLink> $documentLinks
 * @property-read Collection<int, PurchaseOrderDocumentLink> $activeDocumentLinks
 * @property-read PurchaseOrderDocumentLink|null $activeDocumentLink
 */
#[Fillable([
    'ai_extraction_id', 'receiving_upload_id', 'po_number', 'po_number_normalized',
    'po_reference', 'po_date', 'po_date_value', 'arrival_status', 'buyer_company', 'buyer_address',
    'buyer_contact_numbers', 'vendor_name', 'contact_person', 'vendor_email',
    'vendor_mobile', 'vendor_address', 'payment_terms', 'subtotal', 'vat', 'total_amount',
])]
class PoExtraction extends Model
{
    protected function casts(): array
    {
        return [
            'po_date_value' => 'immutable_date',
            'arrival_status' => PurchaseOrderArrivalStatus::class,
        ];
    }

    public function aiExtraction(): BelongsTo
    {
        return $this->belongsTo(AiExtraction::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'receiving_upload_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PoExtractionItem::class)->orderBy('sort_order');
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemFulfillment::class);
    }

    public function documentLinks(): HasMany
    {
        return $this->hasMany(PurchaseOrderDocumentLink::class);
    }

    public function activeDocumentLink(): HasOne
    {
        return $this->hasOne(PurchaseOrderDocumentLink::class)->whereNull('unlinked_at');
    }

    public function activeDocumentLinks(): HasMany
    {
        return $this->hasMany(PurchaseOrderDocumentLink::class)->whereNull('unlinked_at');
    }
}
