<?php

namespace App\Models;

use App\Enums\UploadWorkflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $r2_prefix
 * @property UploadWorkflow $workflow
 * @property bool $is_active
 * @property-read Collection<int, EmailRecipient> $recipients
 * @property-read Collection<int, AuthorizedUploadAccess> $accessGrants
 * @property AuthorizedUploadAccess $pivot
 */
#[Fillable(['name', 'slug', 'r2_prefix', 'workflow', 'is_active'])]
class UploadType extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'workflow' => UploadWorkflow::class,
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'authorized_upload_accesses')
            ->withPivot(['is_active', 'created_by'])
            ->withTimestamps();
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AuthorizedUploadAccess::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailRecipient::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(ReceivingUpload::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
