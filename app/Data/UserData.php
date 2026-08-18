<?php

namespace App\Data;

use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

class UserData extends Data
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public UserStatus $status,
        public ?string $emailVerifiedAt,
        public array $roles,
        public array $permissions,
    ) {}

    public static function fromModel(User $user): self
    {
        $user->loadMissing('roles', 'permissions');

        $status = $user->status instanceof UserStatus
            ? $user->status
            : UserStatus::from((string) $user->status);

        $emailVerifiedAt = $user->email_verified_at instanceof CarbonInterface
            ? $user->email_verified_at->toISOString()
            : $user->email_verified_at;

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            status: $status,
            emailVerifiedAt: $emailVerifiedAt,
            roles: $user->getRoleNames()->values()->all(),
            permissions: $user->getAllPermissions()->pluck('name')->values()->all(),
        );
    }
}
