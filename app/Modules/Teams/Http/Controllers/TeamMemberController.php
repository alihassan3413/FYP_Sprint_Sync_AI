<?php

declare(strict_types=1);

namespace App\Modules\Teams\Http\Controllers;

use App\Models\User;
use App\Modules\Teams\Actions\RemoveWorkspaceMemberAction;
use App\Modules\Teams\Actions\UpdateWorkspaceMemberRoleAction;
use App\Modules\Teams\Http\Requests\UpdateTeamMemberRequest;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamMemberController
{
    /**
     * The roster now lives in People. Old links and bookmarks still work.
     */
    public function index(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($request->user()->can('viewTeam', $workspace), 403);

        return to_route('workspace.people.index', $workspace);
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
