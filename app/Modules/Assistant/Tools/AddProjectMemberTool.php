<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Actions\AddProjectMemberAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;

final class AddProjectMemberTool implements AssistantTool, ProvidesConfirmationDetails
{
    public function __construct(private readonly AddProjectMemberAction $action) {}

    public function name(): string
    {
        return 'add_project_member';
    }

    public function description(): string
    {
        return 'Gives an existing workspace member access to a project. '
            .'Use this when a task cannot be assigned because the person is not on the project yet: '
            .'offer to add them, and only call this once the user agrees. '
            .'Call list_projects for a real project_id, and get_workspace_info with include_members=true to find the email. '
            .'The person must already be in the workspace — this tool cannot invite someone new, use invite_user for that. '
            .'Role is "member" unless the user asks for them to manage the project. '
            .'This is also how a client is given access to a specific project; clients are always added as members.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the project, obtained from list_projects.',
                ],
                'member_email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'Email address of the workspace member to add.',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => ['member', 'manager'],
                    'description' => 'Project role. Defaults to member. Managers can create tasks, sprints and meetings.',
                ],
            ],
            'required' => ['project_id', 'member_email'],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function authorize(ToolContext $context): bool
    {
        $workspace = $context->workspace;

        if ($workspace === null) {
            return false;
        }

        if ($workspace->userHasAtLeast($context->user, UserRole::ADMIN)) {
            return true;
        }

        return $workspace->managedProjectsFor($context->user)->exists();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    public function confirmationDetails(array $args, ToolContext $context): array
    {
        $project = $context->workspace === null ? null : $this->resolveProject($context->workspace, $args, $context->user);
        $member = $project === null ? null : $this->resolveMember($args, $project);

        return [
            'project' => $project === null ? 'Unknown project' : (UntrustedText::inline($project->name) ?? 'Unknown project'),
            'person' => $member === null
                ? 'Unknown workspace member'
                : (UntrustedText::inline($member->name) ?? 'Unknown workspace member'),
            'role' => $member !== null && $context->workspace?->isClient($member) === true
                ? 'Client'
                : $this->role($args)->label(),
            'grants' => $member !== null && $context->workspace?->isClient($member) === true
                ? 'They will see this project, limited by the client permissions of their role.'
                : 'They will be able to see this project and everything in it.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;
        $user = $context->user;

        if ($workspace === null) {
            return ['success' => false, 'error_code' => 'no_workspace', 'error' => 'No active workspace is selected.'];
        }

        $project = $this->resolveProject($workspace, $args, $user);

        if ($project === null) {
            return [
                'success' => false,
                'error_code' => 'project_not_found',
                'error' => 'That project does not exist or you do not have access to it. Use list_projects to see available projects.',
            ];
        }

        if (! $user->can('manageMembers', $project)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to manage members of {$project->name}.",
            ];
        }

        $email = (string) ($args['member_email'] ?? '');
        $member = $this->resolveMember($args, $project);

        if ($member === null) {
            return [
                'success' => false,
                'error_code' => 'not_a_workspace_member',
                'error' => "{$email} is not a member of this workspace, so they cannot be added to a project. Invite them to the workspace first.",
            ];
        }

        if ($project->hasMember($member)) {
            return [
                'success' => false,
                'error_code' => 'already_a_member',
                'error' => "{$member->name} is already on {$project->name}.",
            ];
        }

        $role = $workspace->isClient($member) ? ProjectRole::MEMBER : $this->role($args);

        $this->action->handle($project, $member, $role, $user);

        if ($workspace->isClient($member)) {
            return [
                'success' => true,
                'member' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'role' => $role->value,
                    'is_client' => true,
                    'client_permissions' => $workspace->clientPermissionsFor($member),
                ],
                'url' => route('workspace.projects.show', [
                    'workspace' => $workspace->slug,
                    'project' => $project->id,
                ]),
                'message' => "Gave {$member->name} client access to {$project->name}. "
                    .'What they can do there comes from their client role, and tasks cannot be assigned to them.',
            ];
        }

        return [
            'success' => true,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'project_id' => $project->id,
                'project_name' => $project->name,
                'role' => $role->value,
            ],
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $project->id,
            ]),
            'message' => "Added {$member->name} to {$project->name} as a project {$role->label()}. Tasks can now be assigned to them.",
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveProject(Workspace $workspace, array $args, User $user): ?Project
    {
        if (! isset($args['project_id'])) {
            return null;
        }

        return $workspace->accessibleProjectsFor($user)
            ->whereKey((int) $args['project_id'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveMember(array $args, Project $project): ?User
    {
        $email = trim((string) ($args['member_email'] ?? ''));

        if ($email === '') {
            return null;
        }

        $member = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if ($member === null || ! $project->workspace->hasMember($member)) {
            return null;
        }

        return $member;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function role(array $args): ProjectRole
    {
        return ProjectRole::tryFrom((string) ($args['role'] ?? '')) ?? ProjectRole::MEMBER;
    }
}
