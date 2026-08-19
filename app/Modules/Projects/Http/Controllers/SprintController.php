<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Actions\CompleteSprintAction;
use App\Modules\Projects\Actions\CreateSprintAction;
use App\Modules\Projects\Actions\DeleteSprintAction;
use App\Modules\Projects\Actions\StartSprintAction;
use App\Modules\Projects\Actions\UpdateSprintAction;
use App\Modules\Projects\Http\Requests\CompleteSprintRequest;
use App\Modules\Projects\Http\Requests\StoreSprintRequest;
use App\Modules\Projects\Http\Requests\UpdateSprintRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SprintController
{
    public function store(
        StoreSprintRequest $request,
        Workspace $workspace,
        Project $project,
        CreateSprintAction $action,
    ): RedirectResponse {
        $sprint = $action->handle($project, $request->user(), $request->toDTO());

        return back()->with('success', "Sprint \"{$sprint->name}\" created.");
    }

    public function update(
        UpdateSprintRequest $request,
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        UpdateSprintAction $action,
    ): RedirectResponse {
        $action->handle($sprint, $request->user(), $request->toDTO());

        return back()->with('success', "Sprint \"{$sprint->name}\" updated.");
    }

    public function start(
        Request $request,
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        StartSprintAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('start', $sprint), 403);

        $action->handle($sprint, $request->user());

        return back()->with('success', "Sprint \"{$sprint->name}\" is now running.");
    }

    public function complete(
        CompleteSprintRequest $request,
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        CompleteSprintAction $action,
    ): RedirectResponse {
        $completed = $action->handle($sprint, $request->user(), $request->carryOver(), $request->carryOverTarget());

        return back()->with(
            'success',
            "Sprint \"{$completed->name}\" completed — {$completed->completed_task_count} done, "
                ."{$completed->carried_over_task_count} carried over.",
        );
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        Sprint $sprint,
        DeleteSprintAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('delete', $sprint), 403);

        $name = $sprint->name;

        $action->handle($sprint, $request->user());

        return back()->with('success', "Sprint \"{$name}\" deleted.");
    }
}
