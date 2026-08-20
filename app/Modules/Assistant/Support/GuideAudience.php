<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;

/**
 * Which training material a person should be shown.
 *
 * Teaching someone how to invite a colleague when they will only ever hit a
 * permission error is worse than saying nothing, so every lesson is gated on
 * one of these flags and the curriculum is assembled per user.
 */
final readonly class GuideAudience
{
    public function __construct(
        public bool $hasWorkspace,
        public bool $isClient,
        public bool $managesProjects,
        public bool $canInvite,
        public bool $canCreateProjects,
        public bool $canManageRoles,
    ) {}

    public static function for(ToolContext $context): self
    {
        $workspace = $context->workspace;

        if ($workspace === null) {
            return new self(
                hasWorkspace: false,
                isClient: false,
                managesProjects: false,
                canInvite: false,
                canCreateProjects: false,
                canManageRoles: false,
            );
        }

        $user = $context->user;
        $isAdmin = $workspace->userHasAtLeast($user, UserRole::ADMIN);

        return new self(
            hasWorkspace: true,
            isClient: $workspace->isClient($user),
            managesProjects: $isAdmin || $workspace->managedProjectsFor($user)->exists(),
            canInvite: $user->can('invite', $workspace),
            canCreateProjects: $user->can('create', [Project::class, $workspace]),
            canManageRoles: $user->can('manageRoles', $workspace),
        );
    }

    /**
     * Every audience key maps to exactly one flag, so a lesson can never be
     * gated on a condition this class cannot answer.
     */
    public function admits(string $audience): bool
    {
        return match ($audience) {
            GuideLibrary::AUDIENCE_EVERYONE => true,
            GuideLibrary::AUDIENCE_MEMBER => ! $this->isClient,
            GuideLibrary::AUDIENCE_CLIENT => $this->isClient,
            GuideLibrary::AUDIENCE_PROJECT_MANAGER => $this->managesProjects,
            GuideLibrary::AUDIENCE_INVITE => $this->canInvite,
            GuideLibrary::AUDIENCE_CREATE_PROJECTS => $this->canCreateProjects,
            GuideLibrary::AUDIENCE_MANAGE_ROLES => $this->canManageRoles,
            default => false,
        };
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'has_workspace' => $this->hasWorkspace,
            'is_client' => $this->isClient,
            'manages_projects' => $this->managesProjects,
            'can_invite' => $this->canInvite,
            'can_create_projects' => $this->canCreateProjects,
            'can_manage_roles' => $this->canManageRoles,
        ];
    }

    public function describe(?Workspace $workspace): string
    {
        if (! $this->hasWorkspace) {
            return 'This person is not in a workspace yet. Start them on creating or joining one.';
        }

        if ($this->isClient) {
            return 'This person is a client: an external guest who sees only the projects they have been added to.';
        }

        if ($this->canManageRoles) {
            return 'This person administers the workspace and can be taught everything, including roles and client access.';
        }

        if ($this->managesProjects) {
            return 'This person manages at least one project, so sprints, meetings and board setup are all relevant to them.';
        }

        return 'This person is a workspace member who works inside the projects they are added to.';
    }
}
