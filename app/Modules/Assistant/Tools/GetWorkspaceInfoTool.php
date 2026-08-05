<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;

final class GetWorkspaceInfoTool implements AssistantTool
{
    public function name(): string
    {
        return 'get_workspace_info';
    }

    public function description(): string
    {
        return 'Gets information about the current workspace. Use this when the user asks about the workspace, '
            .'member count, the member list, admins, pending invitations, or their own role.';
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
                'role_filter' => [
                    'type' => 'string',
                    'enum' => ['admin', 'member'],
                    'description' => 'Filter members by role when the user asks for only admins or only members.',
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

    public function authorize(User $user): bool
    {
        $workspace = $user->currentWorkspace;

        return $workspace !== null && $workspace->hasMember($user);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, User $user): array
    {
        $workspace = $user->currentWorkspace;

        if ($workspace === null || ! $workspace->hasMember($user)) {
            return ['success' => false, 'message' => 'No active workspace is selected.'];
        }

        $data = [
            'success' => true,
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'created_at' => $workspace->created_at?->toDateTimeString(),
            ],
            'current_user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $workspace->roleFor($user)?->value,
            ],
            'stats' => $this->stats($workspace),
        ];

        if (($args['include_members'] ?? false) === true) {
            $data['members'] = $this->members($workspace, $args['role_filter'] ?? null);
        }

        if (($args['include_invitations'] ?? false) === true) {
            $data['pending_invitations'] = $this->invitations($workspace);
        }

        return $data;
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
            'pending_invitations_count' => $workspace->pendingInvitations()->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function members(Workspace $workspace, ?string $roleFilter): array
    {
        $query = $workspace->users()->select('users.id', 'users.name', 'users.email');

        if ($roleFilter !== null) {
            $query->wherePivot('role', $roleFilter);
        }

        return $query
            ->orderBy('users.name')
            ->limit(50)
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function invitations(Workspace $workspace): array
    {
        return $workspace->pendingInvitations()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (WorkspaceInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at?->toDateTimeString(),
            ])
            ->all();
    }
}
