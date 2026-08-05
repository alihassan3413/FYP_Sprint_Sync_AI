<?php

declare(strict_types=1);

namespace App\Modules\Teams\Exceptions;

use App\Exceptions\AppException;
use App\Support\Errors\ErrorCode;

final class TeamException extends AppException
{
    public static function cannotRemoveOwner(): self
    {
        return new self(
            code: ErrorCode::TEAM_CANNOT_REMOVE_OWNER,
            status: 422,
            message: 'The workspace owner cannot be removed. Transfer ownership first.',
        );
    }

    public static function cannotRemoveSelf(): self
    {
        return new self(
            code: ErrorCode::TEAM_CANNOT_REMOVE_SELF,
            status: 422,
            message: 'You cannot remove yourself from a workspace.',
        );
    }

    public static function cannotChangeOwnerRole(): self
    {
        return new self(
            code: ErrorCode::TEAM_CANNOT_CHANGE_OWNER_ROLE,
            status: 422,
            message: "The workspace owner's role cannot be changed.",
        );
    }
}
