<?php

declare(strict_types=1);

namespace App\Modules\People\Exceptions;

use App\Exceptions\AppException;
use App\Support\Errors\ErrorCode;

final class PeopleException extends AppException
{
    public static function userNotInWorkspace(): self
    {
        return new self(
            code: ErrorCode::PEOPLE_USER_NOT_MEMBER,
            status: 422,
            message: 'Only members of this workspace can be connected to a team member.',
        );
    }

    public static function stillHasAccess(string $name): self
    {
        return new self(
            code: ErrorCode::PEOPLE_STILL_HAS_ACCESS,
            status: 422,
            message: "{$name} still has SprintSync access to this workspace. Remove their access first, then delete them from the team.",
        );
    }

    public static function invitationPending(string $name): self
    {
        return new self(
            code: ErrorCode::PEOPLE_INVITATION_PENDING,
            status: 422,
            message: "{$name} has a pending invitation to this workspace. Cancel the invitation first, then delete them from the team.",
        );
    }

    public static function onRecurringInvoice(string $name, string $plan, string $client): self
    {
        return new self(
            code: ErrorCode::PEOPLE_ON_RECURRING_INVOICE,
            status: 422,
            message: "{$name} is on the \"{$plan}\" recurring invoice for {$client}. Take them off it or pause it first, then delete them from the team.",
        );
    }

    public static function cannotRemoveSelf(): self
    {
        return new self(
            code: ErrorCode::PEOPLE_CANNOT_REMOVE_SELF,
            status: 422,
            message: 'You can\'t delete your own team profile.',
        );
    }

    public static function userAlreadyLinked(string $userName): self
    {
        return new self(
            code: ErrorCode::PEOPLE_USER_ALREADY_LINKED,
            status: 422,
            message: "{$userName} is already connected to another team member.",
        );
    }
}
