<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $upload_type_id
 * @property string $email
 * @property string $type
 * @property bool $is_active
 * @property-read UploadType $uploadType
 */
#[Fillable(['upload_type_id', 'email', 'type', 'is_active'])]
class EmailRecipient extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function uploadType(): BelongsTo
    {
        return $this->belongsTo(UploadType::class);
    }
}
