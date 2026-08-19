<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Actions\CreateSprintAction;
use App\Modules\Projects\Actions\DeleteSprintAction;
use App\Modules\Projects\Actions\UpdateSprintAction;
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
