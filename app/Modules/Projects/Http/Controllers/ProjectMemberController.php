<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Models\User;
use App\Modules\Projects\Actions\AddProjectMemberAction;
use App\Modules\Projects\Actions\RemoveProjectMemberAction;
use App\Modules\Projects\Actions\UpdateProjectMemberRoleAction;
use App\Modules\Projects\Http\Requests\StoreProjectMemberRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectMemberRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ProjectMemberController
{
    public function store(
        StoreProjectMemberRequest $request,
        Workspace $workspace,
        Project $project,
        AddProjectMemberAction $action,
    ): RedirectResponse {
        $member = $request->member();

        $action->handle($project, $member, $request->role(), $request->user());

        return back()->with('success', "{$member->name} added to {$project->name}.");
    }

    public function update(
        UpdateProjectMemberRequest $request,
        Workspace $workspace,
        Project $project,
        User $member,
        UpdateProjectMemberRoleAction $action,
    ): RedirectResponse {
        $action->handle($project, $member, $request->role(), $request->user());

        return back()->with('success', "{$member->name}'s project role updated.");
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        User $member,
        RemoveProjectMemberAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('manageMembers', $project), 403);

        $action->handle($project, $member, $request->user());

        return back()->with('success', "{$member->name} removed from {$project->name}.");
    }
}
