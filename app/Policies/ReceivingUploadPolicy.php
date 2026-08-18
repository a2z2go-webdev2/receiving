<?php

namespace App\Policies;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Enums\ReviewStatus;
use App\Models\ReceivingUpload;
use App\Models\User;

class ReceivingUploadPolicy
{
    public function view(User $user, ReceivingUpload $upload): bool
    {
        return $user->can(Permission::AccessAdmin->value)
            && $user->can(Permission::ViewUploads->value);
    }

    public function resendNotification(User $user, ReceivingUpload $upload): bool
    {
        if ($upload->email_status !== EmailStatus::Failed) {
            return false;
        }

        if ($user->can(Permission::RetryOperations->value)) {
            return true;
        }

        return $upload->uploader_user_id === $user->getKey()
            && $user->canAccessUploadType($upload->uploadType);
    }

    public function retryExtraction(User $user, ReceivingUpload $upload): bool
    {
        if ($user->can(Permission::RetryOperations->value)) {
            return true;
        }

        return $upload->uploader_user_id === $user->getKey()
            && $user->canAccessUploadType($upload->uploadType);
    }

    public function resendReviewNotification(User $user, ReceivingUpload $upload): bool
    {
        return $upload->uploadType->workflow->requiresReview()
            && $user->can(Permission::RetryOperations->value)
            && $upload->ai_status === AiStatus::Extracted
            && $upload->review_status !== ReviewStatus::Verified;
    }

    public function reprocess(User $user, ReceivingUpload $upload): bool
    {
        return $user->can(Permission::RetryOperations->value)
            && ! in_array($upload->ai_status, [AiStatus::Pending, AiStatus::Processing], true);
    }
}
