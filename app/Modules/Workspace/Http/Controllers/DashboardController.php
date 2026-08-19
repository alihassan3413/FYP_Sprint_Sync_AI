<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Controllers;

use App\Models\User;
use App\Modules\Analytics\Actions\BuildAnalyticsAction;
use App\Modules\Analytics\Actions\ResolveAnalyticsScope;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Workspace\Actions\ResolveWorkspaceCapabilities;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Data\DashboardMeetingData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        BuildAnalyticsAction $analytics,
        ResolveAnalyticsScope $resolveScope,
        ResolveWorkspaceCapabilities $resolveCapabilities,
    ): Response {
        $user = $request->user();

        $capabilities = $resolveCapabilities->handle($workspace, $user);
        $scope = $resolveScope->handle($workspace, $user);
        $accessibleProjectIds = $scope->accessibleProjects->pluck('id');

        $summary = $analytics->handle($scope, []);

        $isClient = $workspace->isClient($user);
        $showsBoardData = ! $isClient || $workspace->allowsClient($user, ClientPermission::BoardView);
        $showsMeetings = ! $isClient || $workspace->allowsClient($user, ClientPermission::MeetingsView);

        return Inertia::render('Dashboard', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'workspaceMeta' => [
                'name' => $workspace->name,
                'created_at' => $workspace->created_at->toIso8601String(),
            ],
            'members' => $capabilities->manageMembers ? $this->members($workspace, $user) : collect(),
            'pendingInvitesCount' => $capabilities->manageMembers ? $workspace->pendingInvitations()->count() : 0,
            'activity' => $capabilities->manageMembers ? $this->activity($workspace, $user) : collect(),
            'onboarding' => $this->onboarding($workspace),
            'upcomingMeetings' => $showsMeetings ? $this->meetings($workspace, $accessibleProjectIds, false) : collect(),
            'pastMeetings' => $showsMeetings ? $this->meetings($workspace, $accessibleProjectIds, true) : collect(),
            'taskProgress' => [
                'total' => $showsBoardData ? $summary->total_tasks : 0,
                'completed' => $showsBoardData ? $summary->completed_tasks : 0,
                'open' => $showsBoardData ? $summary->open_tasks : 0,
                'overdue' => $showsBoardData ? $summary->overdue_tasks : 0,
                'completion_percentage' => $showsBoardData ? $summary->task_completion_percentage : 0,
                'columns' => $showsBoardData ? $summary->tasks_by_column : [],
            ],
            'projects' => $summary->projects,
            'scope' => $summary->scope,
            'capabilities' => $capabilities->forDashboard(),
        ]);
    }

    /**
     * @param  Collection<int, int>  $accessibleProjectIds
     * @return Collection<int, DashboardMeetingData>
     */
    private function meetings(Workspace $workspace, Collection $accessibleProjectIds, bool $past): Collection
    {
        if ($accessibleProjectIds->isEmpty()) {
            return collect();
        }

        $query = Meeting::query()
            ->with('project:id,name')
            ->whereIn('project_id', $accessibleProjectIds)
            ->limit((int) config('workspace.dashboard_meeting_limit'));

        $meetings = $past
            ? $query->past()->orderByDesc('scheduled_at')->get()
            : $query->upcoming()->orderBy('scheduled_at')->get();

        return $meetings->map(fn (Meeting $meeting) => DashboardMeetingData::fromModel(
            $meeting,
            $past,
            $this->meetingUrl($workspace, $meeting),
        ))->values();
    }

    private function meetingUrl(Workspace $workspace, Meeting $meeting): string
    {
        $base = route('workspace.projects.show', [
            'workspace' => $workspace->slug,
            'project' => $meeting->project_id,
        ]);

        return "{$base}?meeting={$meeting->id}";
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function members(Workspace $workspace, User $user): Collection
    {
        $accepted = $workspace->users()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar_path')
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'status' => 'active',
                'last_active_at' => $member->id === $user->id ? now()->toIso8601String() : null,
                'avatar_url' => $member->avatar_url,
                'is_self' => $member->id === $user->id,
            ]);

        $invited = $workspace->pendingInvitations()
            ->get()
            ->map(fn (WorkspaceInvitation $invitation) => [
                'id' => "invitation-{$invitation->id}",
                'name' => $invitation->email,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'status' => 'pending',
                'last_active_at' => null,
                'avatar_url' => null,
                'is_self' => false,
            ]);

        return $accepted->concat($invited)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activity(Workspace $workspace, User $user): Collection
    {
        $limit = (int) config('workspace.dashboard_activity_limit');

        $created = collect([[
            'id' => 'workspace-created',
            'kind' => 'workspace.created',
            'occurred_at' => $workspace->created_at->toIso8601String(),
            'actor' => null,
            'actor_is_self' => false,
            'context' => ['workspace_name' => $workspace->name],
        ]]);

        $joined = $workspace->users()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar_path')
            ->wherePivot('role', '!=', UserRole::OWNER->value)
            ->orderByPivot('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn (User $member) => [
                'id' => "member-joined-{$member->id}",
                'kind' => 'member.joined',
                'occurred_at' => ($member->pivot->created_at ?? $workspace->created_at)->toIso8601String(),
                'actor' => [
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar_url' => $member->avatar_url,
                ],
                'actor_is_self' => $member->id === $user->id,
                'context' => [],
            ]);

        $invited = $workspace->pendingInvitations()
            ->with('invitedBy:id,name,email,avatar_path')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (WorkspaceInvitation $invitation) => [
                'id' => "invitation-sent-{$invitation->id}",
                'kind' => 'member.invited',
                'occurred_at' => $invitation->created_at->toIso8601String(),
                'actor' => $invitation->invitedBy === null ? null : [
                    'name' => $invitation->invitedBy->name,
                    'email' => $invitation->invitedBy->email,
                    'avatar_url' => $invitation->invitedBy->avatar_url,
                ],
                'actor_is_self' => $invitation->invited_by === $user->id,
                'context' => ['invited_email' => $invitation->email],
            ]);

        return $created
            ->concat($joined)
            ->concat($invited)
            ->sortByDesc('occurred_at')
            ->take($limit)
            ->values();
    }

    /**
     * @return array<string, bool>
     */
    private function onboarding(Workspace $workspace): array
    {
        return [
            'workspace_created' => true,
            'first_member_invited' => $workspace->invitations()->exists(),
            'role_assigned' => $workspace->roles()->exists() || $workspace->users()->count() > 1,
            'first_project_created' => $workspace->projects()->exists(),
            'first_sprint_run' => false,
        ];
    }
}
