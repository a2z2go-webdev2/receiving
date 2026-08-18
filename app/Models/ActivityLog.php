<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $receiving_upload_id
 * @property int|null $user_id
 * @property string|null $user_email
 * @property string $role
 * @property string $module
 * @property string $action
 * @property string $status
 * @property string $message
 * @property string|null $error_details
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'receiving_upload_id', 'user_id', 'user_email', 'role', 'module', 'action',
    'status', 'message', 'error_details', 'ip_address', 'created_at',
])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReceivingUpload::class, 'receiving_upload_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
