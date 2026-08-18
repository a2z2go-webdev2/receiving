<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\UploadedFile;
use App\Models\User;

class UploadedFilePolicy
{
    public function view(User $user, UploadedFile $file): bool
    {
        return $user->can(Permission::AccessAdmin->value)
            && $user->can(Permission::ViewUploads->value);
    }
}
