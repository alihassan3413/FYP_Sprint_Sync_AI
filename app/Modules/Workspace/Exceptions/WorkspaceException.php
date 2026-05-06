<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Exceptions;

use App\Exceptions\AppException;
use App\Support\Errors\ErrorCode;
use DateTimeInterface;

/**
 * All workspace-domain errors live here as static factory methods.
 *
 * ─── USAGE ────────────────────────────────────────────────────────
 *
 *   throw WorkspaceException::invitationExpired($invite->expires_at);
 *   throw WorkspaceException::limitReached(5, 5);
 *   throw WorkspaceException::alreadyMember($workspace->name);
 *
 * ─── ADDING A NEW ERROR ───────────────────────────────────────────
 *
 *   1. Add the code to App\Support\Errors\ErrorCode
 *   2. Add a static factory method below
 *   3. Throw it from wherever the rule is enforced
 *
 * That's it — the global handler takes care of everything else.
 */
class WorkspaceException extends AppException
{
    public static function invitationExpired(?DateTimeInterface $expiredAt = null): self
    {
        return new self(
            code:    ErrorCode::WORKSPACE_INVITATION_EXPIRED,
            status:  410, // Gone — the resource existed but is no longer available
            message: 'This invitation has expired. Please request a new one.',
            meta:    $expiredAt ? ['expired_at' => $expiredAt->format('c')] : [],
        );
    }

    public static function invitationInvalid(string $reason = 'This invitation link is no longer valid.'): self
    {
        return new self(
            code:    ErrorCode::WORKSPACE_INVITATION_INVALID,
            status:  404,
            message: $reason,
        );
    }

    public static function alreadyMember(string $workspaceName): self
    {
        return new self(
            code:    ErrorCode::WORKSPACE_ALREADY_MEMBER,
            status:  409, // Conflict — current state prevents the action
            message: "You're already a member of {$workspaceName}.",
            meta:    ['workspace_name' => $workspaceName],
        );
    }

    public static function limitReached(int $limit, int $current, ?string $upgradeUrl = null): self
    {
        $meta = ['limit' => $limit, 'current' => $current];

        if ($upgradeUrl !== null) {
            $meta['upgrade_url'] = $upgradeUrl;
        }

        return new self(
            code:    ErrorCode::WORKSPACE_LIMIT_REACHED,
            status:  403,
            message: "You've reached your limit of {$limit} workspaces.",
            meta:    $meta,
        );
    }

    public static function notFound(): self
    {
        return new self(
            code:    ErrorCode::WORKSPACE_NOT_FOUND,
            status:  404,
            message: 'Workspace not found.',
        );
    }

    public static function noActiveWorkspace(): self
    {
        return new self(
            code:    ErrorCode::WORKSPACE_NO_ACTIVE,
            status:  400,
            message: 'No active workspace. Please select a workspace to continue.',
        );
    }
}
