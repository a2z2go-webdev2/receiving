<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\ReviewStatus;
use App\Enums\UploadProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $submission_id
 * @property int $upload_type_id
 * @property int $uploader_user_id
 * @property string $uploader_email
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $location_accuracy_meters
 * @property CarbonImmutable|null $location_captured_at
 * @property string $r2_bucket
 * @property string|null $r2_prefix
 * @property int $file_count
 * @property UploadProcessingStatus $processing_status
 * @property EmailStatus $email_status
 * @property EmailStatus $review_email_status
 * @property AiStatus $ai_status
 * @property ReviewStatus $review_status
 * @property string|null $failure_reason
 * @property string|null $review_email_failure_reason
 * @property CarbonImmutable|null $upload_completed_at
 * @property CarbonImmutable|null $notification_sent_at
 * @property CarbonImmutable|null $review_notification_sent_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read UploadType $uploadType
 * @property-read User $uploader
 * @property-read Collection<int, UploadedFile> $files
 * @property-read Collection<int, AiExtraction> $extractions
 * @property-read Collection<int, ReviewLink> $reviewLinks
 * @property-read Collection<int, PoExtraction> $poExtractions
 * @property-read Collection<int, PurchaseOrderItemArrival> $purchaseOrderItemArrivals
 */
#[Fillable([
    'submission_id', 'upload_type_id', 'uploader_user_id', 'uploader_email', 'r2_bucket', 'r2_prefix',
    'latitude', 'longitude', 'location_accuracy_meters', 'location_captured_at',
    'file_count', 'processing_status', 'email_status', 'review_email_status', 'ai_status', 'review_status',
    'failure_reason', 'review_email_failure_reason', 'upload_completed_at', 'notification_sent_at',
    'review_notification_sent_at',
])]
class ReceivingUpload extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'processing_status' => UploadProcessingStatus::class,
            'email_status' => EmailStatus::class,
            'review_email_status' => EmailStatus::class,
            'ai_status' => AiStatus::class,
            'review_status' => ReviewStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'location_accuracy_meters' => 'float',
            'location_captured_at' => 'immutable_datetime',
            'upload_completed_at' => 'immutable_datetime',
            'notification_sent_at' => 'immutable_datetime',
            'review_notification_sent_at' => 'immutable_datetime',
        ];
    }

    public function uploadType(): BelongsTo
    {
        return $this->belongsTo(UploadType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(UploadedFile::class);
    }

    public function extractions(): HasMany
    {
        return $this->hasMany(AiExtraction::class);
    }

    public function reviewLinks(): HasMany
    {
        return $this->hasMany(ReviewLink::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function getSerialNumberAttribute(): int
    {
        return (int) $this->getKey();
    }

    public function poExtractions(): HasMany
    {
        return $this->hasMany(PoExtraction::class);
    }

    public function purchaseOrderItemArrivals(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemArrival::class);
    }
}
