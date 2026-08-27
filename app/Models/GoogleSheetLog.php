<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $sheet_slug
 * @property int $serial_number
 * @property string|null $timestamp
 * @property string|null $drive_folder_link
 * @property int $file_count
 * @property string|null $email_status
 * @property string|null $ai_status
 * @property string|null $review_status
 * @property string|null $review_token
 * @property string|null $reviewed_at
 * @property string|null $reviewed_by
 * @property string|null $review_token_created_at
 * @property string|null $review_expires_at
 * @property string|null $uploader_location
 * @property bool $is_synced_to_db
 * @property int|null $synced_receiving_upload_id
 * @property CarbonImmutable|null $synced_at
 * @property string|null $error_message
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ReceivingUpload|null $syncedUpload
 */
#[Fillable([
    'sheet_slug', 'serial_number', 'timestamp', 'drive_folder_link', 'file_count',
    'email_status', 'ai_status', 'review_status', 'review_token', 'reviewed_at',
    'reviewed_by', 'review_token_created_at', 'review_expires_at', 'uploader_location',
    'is_synced_to_db', 'synced_receiving_upload_id', 'synced_at', 'error_message',
])]
class GoogleSheetLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'file_count' => 'integer',
            'is_synced_to_db' => 'boolean',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(GoogleSheetFile::class, 'sheet_slug', 'sheet_slug')
            ->whereColumn('google_sheet_files.serial_number', 'google_sheet_logs.serial_number');
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(GoogleSheetExtraction::class, 'sheet_slug', 'sheet_slug')
            ->whereColumn('google_sheet_extractions.serial_number', 'google_sheet_logs.serial_number');
    }

    protected $appends = ['has_update_available'];

    public function getHasUpdateAvailableAttribute(): bool
    {
        if (! $this->is_synced_to_db || ! $this->synced_at || ! $this->updated_at) {
            return false;
        }

        return $this->updated_at->gt($this->synced_at);
    }

    public function syncedUpload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'synced_receiving_upload_id');
    }
}
