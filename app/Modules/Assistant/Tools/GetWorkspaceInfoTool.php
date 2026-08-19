<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class GetWorkspaceInfoTool implements AssistantTool
{
    public function name(): string
    {
        return 'get_workspace_info';
    }

    public function description(): string
    {
        return 'Gets information about the current workspace. Use this when the user asks about the workspace, '
            .'member count, the member list, admins, clients, pending invitations, custom roles and what they can do, '
            .'or their own role. Also use it with include_roles=true to resolve a custom role name before inviting '
            .'someone with that role, and to see which client permissions a role grants.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_members' => [
                    'type' => 'boolean',
                    'description' => 'Include the full member list. Use true when the user asks who the members are.',
                ],
                'include_invitations' => [
                    'type' => 'boolean',
                    'description' => 'Include pending invitations. Use true when the user asks about invites.',
                ],
                'include_roles' => [
                    'type' => 'boolean',
                    'description' => 'Include the workspace custom roles with the permissions each one grants. '
                        .'Use true when the user asks about custom roles, permissions, or wants to invite someone with a custom role.',
                ],
                'role_filter' => [
                    'type' => 'string',
                    'enum' => ['admin', 'member', 'client'],
                    'description' => 'Filter members by base role when the user asks for only admins, only members, or only clients.',
                ],
                'custom_role_filter' => [
                    'type' => 'string',
                    'description' => 'Filter members by custom role name when the user asks who holds a custom role. '
                        .'Must match a name returned by include_roles.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->workspace !== null;
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
            return ['success' => false, 'message' => 'No active workspace is selected.'];
        }

        if ($workspace->isClient($user)) {
            return $this->forClient($workspace, $user);
        }

        $data = [
            'success' => true,
            'workspace' => [
                'id' => $workspace->id,
                'name' => UntrustedText::inline($workspace->name),
                'slug' => UntrustedText::inline($workspace->slug),
                'created_at' => $workspace->created_at?->toDateTimeString(),
            ],
            'current_user' => [
                'id' => $user->id,
                'name' => UntrustedText::inline($user->name),
                'email' => UntrustedText::inline($user->email),
                'role' => $workspace->roleFor($user)?->value,
                'custom_role' => UntrustedText::inline($workspace->customRoleFor($user)?->name),
                'granted_permissions' => $workspace->grantedPermissionsFor($user),
            ],
            'stats' => $this->stats($workspace),
        ];

        $customRoleNames = $workspace->roles()->orderBy('name')->pluck('name', 'id');

        if (($args['include_members'] ?? false) === true) {
            $data['members'] = $this->members(
                $workspace,
                $customRoleNames,
                $args['role_filter'] ?? null,
                isset($args['custom_role_filter']) ? (string) $args['custom_role_filter'] : null,
            );
        }

        if (($args['include_invitations'] ?? false) === true) {
            $data['pending_invitations'] = $this->invitations($workspace, $customRoleNames);
        }

        if (($args['include_roles'] ?? false) === true) {
            $data['custom_roles'] = $this->customRoles($workspace);
            $data['base_roles'] = $this->baseRoles();
        }

        return $data;
    }

    /**
     * A client is an outside guest: they never see the member roster, the invitations
     * or the workspace-wide counts, only their own access.
     *
     * @return array<string, mixed>
     */
    private function forClient(Workspace $workspace, User $user): array
    {
        return [
            'success' => true,
            'workspace' => [
                'id' => $workspace->id,
                'name' => UntrustedText::inline($workspace->name),
                'slug' => UntrustedText::inline($workspace->slug),
            ],
            'current_user' => [
                'id' => $user->id,
                'name' => UntrustedText::inline($user->name),
                'email' => UntrustedText::inline($user->email),
                'role' => UserRole::CLIENT->value,
                'custom_role' => UntrustedText::inline($workspace->customRoleFor($user)?->name),
                'granted_permissions' => $workspace->clientPermissionsFor($user),
            ],
            'projects_count' => $workspace->accessibleProjectsFor($user)->count(),
            'note' => 'This user is a client. They only see the projects they have been added to, '
                .'and the member list, invitations and workspace settings are not available to them.',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function stats(Workspace $workspace): array
    {
        return [
            'members_count' => $workspace->users()->count(),
            'admins_count' => $workspace->users()->wherePivot('role', 'admin')->count(),
            'normal_members_count' => $workspace->users()->wherePivot('role', 'member')->count(),
            'clients_count' => $workspace->users()->wherePivot('role', 'client')->count(),
            'pending_invitations_count' => $workspace->pendingInvitations()->count(),
            'custom_roles_count' => $workspace->roles()->count(),
        ];
    }

    /**
     * @param  Collection<int, string>  $customRoleNames
     * @return array<int, array<string, mixed>>
     */
    private function members(
        Workspace $workspace,
        Collection $customRoleNames,
        ?string $roleFilter,
        ?string $customRoleFilter,
    ): array {
        $query = $workspace->users()->select('users.id', 'users.name', 'users.email');

        if ($roleFilter !== null) {
            $query->wherePivot('role', $roleFilter);
        }

        if ($customRoleFilter !== null && trim($customRoleFilter) !== '') {
            $matchedIds = $customRoleNames
                ->filter(fn (string $name) => Str::lower($name) === Str::lower(trim($customRoleFilter)))
                ->keys()
                ->all();

            $query->wherePivotIn('workspace_role_id', $matchedIds === [] ? [0] : $matchedIds);
        }

        return $query
            ->orderBy('users.name')
            ->limit(50)
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => UntrustedText::inline($member->name),
                'email' => UntrustedText::inline($member->email),
                'role' => $member->pivot->role,
                'custom_role' => UntrustedText::inline($customRoleNames->get($member->pivot->workspace_role_id)),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, string>  $customRoleNames
     * @return array<int, array<string, mixed>>
     */
    private function invitations(Workspace $workspace, Collection $customRoleNames): array
    {
        return $workspace->pendingInvitations()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (WorkspaceInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => UntrustedText::inline($invitation->email),
                'role' => $invitation->role->value,
                'custom_role' => UntrustedText::inline($customRoleNames->get($invitation->workspace_role_id)),
                'expires_at' => $invitation->expires_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customRoles(Workspace $workspace): array
    {
        return $workspace->roles()
            ->withCount('members')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (WorkspaceRole $role) => [
                'id' => $role->id,
                'name' => UntrustedText::inline($role->name),
                'slug' => UntrustedText::inline($role->slug),
                'permissions' => array_values(array_filter(
                    WorkspacePermission::values(),
                    fn (string $permission) => $role->grants($permission),
                )),
                'client_permissions' => array_values(array_filter(
                    ClientPermission::values(),
                    fn (string $permission) => $role->grants($permission),
                )),
                'members_count' => (int) ($role->members_count ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function baseRoles(): array
    {
        return array_map(
            fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
                'invitable' => in_array($role->value, UserRole::invitationRoles(), true) ? 'yes' : 'no',
                'scope' => $role->isClient()
                    ? 'Only the projects they are added to, limited by the client permissions of their custom role.'
                    : 'The whole workspace, subject to their permissions.',
            ],
            UserRole::cases(),
        );
    }
}
