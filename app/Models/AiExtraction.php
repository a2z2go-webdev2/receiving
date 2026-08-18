<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Enums\PurchaseOrderLinkStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Services\CorrectedDataMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $receiving_upload_id
 * @property int $uploaded_file_id
 * @property string|null $document_type
 * @property string|null $invoice_number
 * @property string|null $po_number
 * @property string|null $po_number_normalized
 * @property string|null $po_date
 * @property int|null $po_date_filled_from_po_extraction_id
 * @property PurchaseOrderLinkStatus $po_link_status
 * @property array<string, mixed>|null $raw_extracted_json
 * @property array<string, mixed>|null $corrected_json
 * @property AiStatus $ai_status
 * @property ReviewStatus $review_status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $extracted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $reviewed_by_email
 * @property-read ReceivingUpload $upload
 * @property-read UploadedFile $file
 * @property-read PoExtraction|null $poExtraction
 * @property-read PurchaseOrderDocumentLink|null $activePurchaseOrderLink
 * @property-read Collection<int, PurchaseOrderDocumentLink> $purchaseOrderLinks
 */
#[Fillable([
    'receiving_upload_id', 'uploaded_file_id', 'document_type', 'raw_extracted_json',
    'invoice_number', 'po_number', 'po_number_normalized', 'po_date',
    'po_date_filled_from_po_extraction_id', 'po_link_status', 'corrected_json', 'ai_status',
    'review_status', 'failure_reason', 'extracted_at', 'reviewed_at', 'reviewed_by_email',
])]
class AiExtraction extends Model
{
    protected static function booted(): void
    {
        static::saving(function (AiExtraction $extraction): void {
            if ($extraction->isDirty('raw_extracted_json') || $extraction->isDirty('corrected_json')) {
                $metadata = is_array($extraction->corrected_json)
                    ? $extraction->corrected_json
                    : $extraction->raw_extracted_json;
                $extraction->invoice_number = CorrectedDataMetadata::invoiceNumber($metadata);
                $poNumber = CorrectedDataMetadata::poNumber($metadata);
                $poDate = CorrectedDataMetadata::poDate($metadata);

                $extraction->po_number = $poNumber;
                $extraction->po_number_normalized = CorrectedDataMetadata::normalizedIdentifier($poNumber);
                $extraction->po_date = $poDate;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'raw_extracted_json' => 'array',
            'corrected_json' => 'array',
            'ai_status' => AiStatus::class,
            'review_status' => ReviewStatus::class,
            'po_link_status' => PurchaseOrderLinkStatus::class,
            'extracted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return array<string, mixed>|null */
    public function preferredData(): ?array
    {
        if (is_array($this->corrected_json) && $this->corrected_json !== []) {
            return $this->corrected_json;
        }

        return is_array($this->raw_extracted_json) ? $this->raw_extracted_json : null;
    }

    public function dataProvenance(): string
    {
        return $this->review_status === ReviewStatus::Verified
            && is_array($this->corrected_json)
            && $this->corrected_json !== []
                ? 'verified'
                : 'unverified';
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'receiving_upload_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'uploaded_file_id');
    }

    public function poExtraction(): HasOne
    {
        return $this->hasOne(PoExtraction::class);
    }

    public function poDateSource(): BelongsTo
    {
        return $this->belongsTo(PoExtraction::class, 'po_date_filled_from_po_extraction_id');
    }

    public function purchaseOrderLinks(): HasMany
    {
        return $this->hasMany(PurchaseOrderDocumentLink::class);
    }

    public function activePurchaseOrderLink(): HasOne
    {
        return $this->hasOne(PurchaseOrderDocumentLink::class)->whereNull('unlinked_at');
    }
}
