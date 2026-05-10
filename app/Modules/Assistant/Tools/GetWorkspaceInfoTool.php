<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;

final class GetWorkspaceInfoTool implements AssistantTool
{
    public function name(): string
    {
        return 'get_workspace_info';
    }

    public function description(): string
    {
        return 'Gets complete information about the current workspace. Use this when the user asks about the current workspace, workspace details, member count, members list, admins, pending invitations, or their role in the workspace.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_members' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include the full list of workspace members. Use true when the user asks who the members are or asks for the member list.',
                ],
                'include_invitations' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include pending invitations. Use true when the user asks about invites or pending invitations.',
                ],
                'role_filter' => [
                    'type' => 'string',
                    'enum' => ['admin', 'member'],
                    'description' => 'Optional member role filter. Use this when the user asks for only admins or only members.',
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
        return $user->currentWorkspace !== null;
    }

    public function execute(array $args, User $user): array
    {
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return [
                'success' => false,
                'message' => 'No active workspace is selected.',
            ];
        }

        $includeMembers = (bool) ($args['include_members'] ?? false);
        $includeInvitations = (bool) ($args['include_invitations'] ?? false);
        $roleFilter = $args['role_filter'] ?? null;

        $currentUserMembership = $workspace->users()
            ->whereKey($user->id)
            ->first();

        $membersQuery = $workspace->users();

        if ($roleFilter) {
            $membersQuery->wherePivot('role', $roleFilter);
        }

        $membersCount = $workspace->users()->count();

        $adminsCount = $workspace->users()
            ->wherePivot('role', 'admin')
            ->count();

        $normalMembersCount = $workspace->users()
            ->wherePivot('role', 'member')
            ->count();

        $pendingInvitationsQuery = $workspace->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());

        $data = [
            'success' => true,
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'created_at' => optional($workspace->created_at)->toDateTimeString(),
                'updated_at' => optional($workspace->updated_at)->toDateTimeString(),
            ],
            'current_user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $currentUserMembership?->pivot?->role,
            ],
            'stats' => [
                'members_count' => $membersCount,
                'admins_count' => $adminsCount,
                'normal_members_count' => $normalMembersCount,
                'pending_invitations_count' => (clone $pendingInvitationsQuery)->count(),
            ],
        ];

        if ($includeMembers) {
            $data['members'] = $membersQuery
                ->select('users.id', 'users.name', 'users.email')
                ->orderBy('users.name')
                ->get()
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                ])
                ->values()
                ->all();
        }

        if ($includeInvitations) {
            $data['pending_invitations'] = $pendingInvitationsQuery
                ->latest()
                ->get()
                ->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'expires_at' => optional($invitation->expires_at)->toDateTimeString(),
                    'created_at' => optional($invitation->created_at)->toDateTimeString(),
                ])
                ->values()
                ->all();
        }

        return $data;
    }
}
