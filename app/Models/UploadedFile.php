<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Enums\CompressionStatus;
use App\Enums\ReviewStatus;
use App\Enums\ValidationStatus;
use App\Enums\VirusScanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $receiving_upload_id
 * @property string $original_file_name
 * @property string $sanitized_file_name
 * @property string $stored_file_name
 * @property string $file_extension
 * @property string $r2_bucket
 * @property string|null $r2_object_key
 * @property string $r2_staging_object_key
 * @property int $original_file_size
 * @property int|null $compressed_file_size
 * @property int|null $final_file_size
 * @property string $declared_content_type
 * @property string|null $content_type
 * @property string|null $file_hash
 * @property ValidationStatus $validation_status
 * @property VirusScanStatus $virus_scan_status
 * @property CompressionStatus $compression_status
 * @property AiStatus $ai_status
 * @property ReviewStatus $review_status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $uploaded_at
 * @property-read ReceivingUpload $upload
 * @property-read AiExtraction|null $extraction
 */
#[Fillable([
    'receiving_upload_id', 'original_file_name', 'sanitized_file_name', 'stored_file_name',
    'file_extension', 'r2_bucket', 'r2_object_key', 'r2_staging_object_key',
    'original_file_size', 'compressed_file_size', 'final_file_size', 'declared_content_type',
    'content_type', 'file_hash', 'validation_status', 'virus_scan_status',
    'compression_status', 'ai_status', 'review_status', 'failure_reason', 'uploaded_at',
])]
class UploadedFile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'validation_status' => ValidationStatus::class,
            'virus_scan_status' => VirusScanStatus::class,
            'compression_status' => CompressionStatus::class,
            'ai_status' => AiStatus::class,
            'review_status' => ReviewStatus::class,
            'uploaded_at' => 'immutable_datetime',
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'receiving_upload_id');
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(AiExtraction::class);
    }

    public function resolvedR2ObjectKey(): ?string
    {
        if ($this->r2_object_key === null) {
            return null;
        }

        $key = (string) $this->r2_object_key;
        if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
            $parsedPath = parse_url($key, PHP_URL_PATH);
            if ($parsedPath) {
                $key = rawurldecode(ltrim($parsedPath, '/'));
            }
        }

        $bucket = (string) config('filesystems.disks.r2.bucket');
        if ($bucket !== '' && str_starts_with($key, $bucket.'/')) {
            $key = substr($key, strlen($bucket) + 1);
        } elseif (preg_match('#^receiving-[a-z0-9_-]+/(.+)#i', $key, $m)) {
            $key = $m[1];
        }

        return $key;
    }
}
