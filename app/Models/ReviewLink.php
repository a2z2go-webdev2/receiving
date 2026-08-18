<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $receiving_upload_id
 * @property int $upload_type_id
 * @property string $email
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property-read ReceivingUpload $upload
 * @property-read UploadType $uploadType
 */
#[Fillable(['receiving_upload_id', 'email', 'upload_type_id', 'token_hash', 'expires_at', 'used_at'])]
class ReviewLink extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'receiving_upload_id');
    }

    public function uploadType(): BelongsTo
    {
        return $this->belongsTo(UploadType::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
