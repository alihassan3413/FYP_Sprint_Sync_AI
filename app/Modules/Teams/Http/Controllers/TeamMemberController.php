<?php

declare(strict_types=1);

namespace App\Modules\Teams\Http\Controllers;

use App\Models\User;
use App\Modules\Teams\Actions\RemoveWorkspaceMemberAction;
use App\Modules\Teams\Actions\UpdateWorkspaceMemberRoleAction;
use App\Modules\Teams\Http\Requests\UpdateTeamMemberRequest;
use App\Modules\Teams\Services\TeamRoster;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TeamMemberController
{
    public function __construct(private readonly TeamRoster $roster) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('teams/index', [
            'members' => $this->roster->forWorkspace($workspace, $request->user()),
            'canManageMembers' => $request->user()->can('manageMembers', $workspace),
            'canInviteMembers' => $request->user()->can('invite', $workspace),
            'seatLimit' => (int) config('workspace.seat_limit'),
            'workspaceRoles' => $workspace->roles()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (WorkspaceRole $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                ])
                ->values(),
        ]);
    }

    public function update(
        UpdateTeamMemberRequest $request,
        Workspace $workspace,
        User $user,
        UpdateWorkspaceMemberRoleAction $action,
    ): RedirectResponse {
        $action->handle($workspace, $user, $request->role(), $request->customRole(), $request->user());

        return back()->with('success', "{$user->name}'s role updated.");
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        User $user,
        RemoveWorkspaceMemberAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('manageMembers', $workspace), 403);

        $action->handle($workspace, $user, $request->user());

        return back()->with('success', "{$user->name} was removed from the workspace.");
    }
}
