<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $upload_type_id
 * @property bool $is_active
 * @property int|null $created_by
 * @property-read User $user
 * @property-read UploadType $uploadType
 */
#[Fillable(['user_id', 'upload_type_id', 'is_active', 'created_by'])]
class AuthorizedUploadAccess extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploadType(): BelongsTo
    {
        return $this->belongsTo(UploadType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
