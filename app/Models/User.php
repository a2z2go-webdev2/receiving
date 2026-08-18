<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserStatus $status
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        $status = $this->status instanceof UserStatus
            ? $this->status
            : UserStatus::from((string) $this->status);

        return $status === UserStatus::Active;
    }

    public function dashboardRouteName(): string
    {
        return match (true) {
            $this->can(Permission::AccessAdmin->value) => 'admin.dashboard',
            $this->can(Permission::AccessWarehouse->value) => 'warehouse.dashboard',
            $this->can(Permission::AccessUploader->value) => 'uploader.dashboard',
            $this->can(Permission::AccessDriver->value) => 'driver.dashboard',
            default => 'dashboard',
        };
    }

    public function canManageAccountSettings(): bool
    {
        return $this->can(Permission::AccessAdmin->value)
            || ! $this->can(Permission::AccessUploader->value);
    }

    public function uploadTypes(): BelongsToMany
    {
        return $this->belongsToMany(UploadType::class, 'authorized_upload_accesses')
            ->withPivot(['is_active', 'created_by'])
            ->withTimestamps();
    }

    public function uploadAccesses(): HasMany
    {
        return $this->hasMany(AuthorizedUploadAccess::class);
    }

    public function receivingUploads(): HasMany
    {
        return $this->hasMany(ReceivingUpload::class, 'uploader_user_id');
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function canAccessUploadType(UploadType $uploadType): bool
    {
        return $this->isActive()
            && $uploadType->is_active
            && $this->uploadAccesses()
                ->where('upload_type_id', $uploadType->getKey())
                ->where('is_active', true)
                ->exists();
    }
}
