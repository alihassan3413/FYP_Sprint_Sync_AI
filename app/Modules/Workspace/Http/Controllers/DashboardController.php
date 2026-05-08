<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Workspace $workspace): Response
    {
        $user = auth()->user();

        return Inertia::render('Dashboard', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            // NOTE: The global Inertia middleware shares `workspace` with the
            // shape { current, available }. We MUST NOT use the same key here
            // — page props override shared props, and the sidebar/switcher
            // read `workspace.current` from the shared prop.
            //
            // So this page-level prop carries dashboard-specific metadata
            // under a different key.
            'workspaceMeta' => [
                'name' => $workspace->name,
                'created_at' => $workspace->created_at->toIso8601String(),
                'plan' => $workspace->plan?->name ?? 'Pro',
            ],
            'members' => $this->members($workspace, $user->id),
            'pendingInvitesCount' => $this->pendingInvitesCount($workspace),
            'activity' => $this->activity($workspace, $user),
            'onboarding' => $this->onboarding($workspace),
        ]);
    }

    /**
     * Workspace members.
     */
    private function members(Workspace $workspace, int $currentUserId): Collection
    {
        return $workspace->users()
            ->select('users.id', 'users.name', 'users.email')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role ?? 'member',
                'status' => $u->id === $currentUserId ? 'active' : 'offline',
                'last_active_at' => $u->id === $currentUserId
                    ? now()->toIso8601String()
                    : null,
                'avatar_url' => null,
                'is_self' => $u->id === $currentUserId,
            ])
            ->values();
    }

    private function pendingInvitesCount(Workspace $workspace): int
    {
        return 0;
    }

    private function activity(Workspace $workspace, $user): Collection
    {
        $entries = collect();

        $entries->push([
            'id' => 'workspace-created',
            'kind' => 'workspace.created',
            'occurred_at' => $workspace->created_at->toIso8601String(),
            'actor' => null,
            'actor_is_self' => false,
            'context' => ['workspace_name' => $workspace->name],
        ]);

        $workspace->users()
            ->select('users.id', 'users.name', 'users.email')
            ->orderByPivot('created_at')
            ->limit(7)
            ->get()
            ->each(function ($u) use ($entries, $user, $workspace) {
                if (isset($workspace->owner_id) && $u->id === $workspace->owner_id) {
                    return;
                }

                $entries->push([
                    'id' => "member-joined-{$u->id}",
                    'kind' => 'member.joined',
                    'occurred_at' => optional($u->pivot->created_at)->toIso8601String()
                                       ?? $workspace->created_at->toIso8601String(),
                    'actor' => [
                        'name' => $u->name,
                        'email' => $u->email,
                        'avatar_url' => null,
                    ],
                    'actor_is_self' => $u->id === $user->id,
                    'context' => [],
                ]);
            });

        return $entries->sortByDesc('occurred_at')->values();
    }

    private function onboarding(Workspace $workspace): array
    {
        $memberCount = $workspace->users()->count();

        return [
            'workspace_created' => true,
            'first_member_invited' => $memberCount > 1,
            'role_assigned' => $memberCount > 1,
            'first_project_created' => false,
            'first_sprint_run' => false,
        ];
    }
}
