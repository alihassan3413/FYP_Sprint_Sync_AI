<?php

declare(strict_types=1);

namespace App\Support\Errors;

final class ErrorCode
{
    // generic error codes
    public const INTERNAL_ERROR = 'INTERNAL.ERROR';

    public const NOT_FOUND = 'NOT.FOUND';

    public const BAD_REQUEST = 'BAD.REQUEST';

    public const RATE_LIMITED = 'RATE.LIMITED';

    public const VALIDATION_FAILED = 'VALIDATION.FAILED';

    //  auth error codes
    public const AUTH_UNAUTHENTICATED = 'AUTH.UNAUTHENTICATED';

    public const AUTH_FORBIDDEN = 'AUTH.FORBIDDEN';

    public const AUTH_SESSION_EXPIRED = 'AUTH.SESSION.EXPIRED';

    // workspace error codes
    public const WORKSPACE_NOT_FOUND = 'WORKSPACE.NOT.FOUND';

    public const WORKSPACE_LIMIT_REACHED = 'WORKSPACE.LIMIT.REACHED';

    public const WORKSPACE_ALREADY_MEMBER = 'WORKSPACE.ALREADY.MEMBER';

    public const WORKSPACE_INVITATION_EXPIRED = 'WORKSPACE.INVITATION.EXPIRED';

    public const WORKSPACE_INVITATION_INVALID = 'WORKSPACE.INVITATION.INVALID';

    public const WORKSPACE_SLUG_ALREADY_EXISTS = 'WORKSPACE.SLUG.ALREADY.EXISTS';

    public const WORKSPACE_CREATION_FAILED = 'WORKSPACE.CREATION.FAILED';

    public const WORKSPACE_NO_ACTIVE = 'WORKSPACE.NO.ACTIVE';

    private function __construct()
    {
        // prevent instantiation
    }
}
