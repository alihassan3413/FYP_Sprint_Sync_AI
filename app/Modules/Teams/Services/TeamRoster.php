<?php

declare(strict_types=1);

namespace App\Modules\Teams\Services;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Support\Collection;

final class TeamRoster
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forWorkspace(Workspace $workspace, User $viewer): Collection
    {
        $customRoleNames = $workspace->roles()->pluck('name', 'id');
        $canSeeInviteLinks = $viewer->can('invite', $workspace);

        $members = $workspace->users()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar_path')
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'workspace_role_id' => $member->pivot->workspace_role_id,
                'workspace_role_name' => $customRoleNames->get($member->pivot->workspace_role_id),
                'status' => 'active',
                'last_active_at' => $member->id === $viewer->id ? now()->toIso8601String() : null,
                'avatar_url' => $member->avatar_url,
                'is_self' => $member->id === $viewer->id,
                'invite_url' => null,
            ]);

        $invited = $workspace->pendingInvitations()
            ->orderBy('email')
            ->get()
            ->map(fn (WorkspaceInvitation $invitation) => [
                'id' => "invitation-{$invitation->id}",
                'invitation_id' => $invitation->id,
                'name' => $invitation->email,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'workspace_role_id' => null,
                'workspace_role_name' => null,
                'status' => 'pending',
                'last_active_at' => null,
                'avatar_url' => null,
                'is_self' => false,
                'invite_url' => $canSeeInviteLinks
                    ? route('workspace.invitations.accept', ['token' => $invitation->token])
                    : null,
            ]);

        return $members->concat($invited)->values();
    }
}
