<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Policies;

use App\Models\User;
use App\Modules\Attachments\Models\Attachment;
use App\UserRole;

final class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        if ($attachment->uploaded_by === $user->id) {
            return true;
        }

        $attachable = $attachment->attachable;

        if ($attachable === null) {
            return false;
        }

        return $user->can('view', $attachable);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if ($attachment->uploaded_by === $user->id) {
            return true;
        }

        return $attachment->workspace->userHasAtLeast($user, UserRole::ADMIN);
    }
}
