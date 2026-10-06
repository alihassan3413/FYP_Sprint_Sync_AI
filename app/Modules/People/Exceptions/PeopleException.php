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

    public static function userAlreadyLinked(string $userName): self
    {
        return new self(
            code: ErrorCode::PEOPLE_USER_ALREADY_LINKED,
            status: 422,
            message: "{$userName} is already connected to another team member.",
        );
    }
}
