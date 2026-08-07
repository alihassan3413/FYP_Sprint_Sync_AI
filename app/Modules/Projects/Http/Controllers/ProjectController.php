<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Actions\CreateProjectAction;
use App\Modules\Projects\Actions\DeleteProjectAction;
use App\Modules\Projects\Actions\UpdateProjectAction;
use App\Modules\Projects\Data\ProjectData;
use App\Modules\Projects\Http\Requests\StoreProjectRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProjectController
{
    public function index(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('projects/index', [
            'projects' => $workspace->projects()
                ->latest()
                ->get()
                ->map(ProjectData::fromModel(...))
                ->values(),
            'canManageProjects' => $request->user()->can('create', [Project::class, $workspace]),
        ]);
    }

    public function show(Request $request, Workspace $workspace, Project $project): Response
    {
        return Inertia::render('projects/show', [
            'project' => ProjectData::fromModel($project),
            'canManageProjects' => $request->user()->can('update', $project),
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        Workspace $workspace,
        CreateProjectAction $action,
    ): RedirectResponse {
        $project = $action->handle($workspace, $request->toDTO());

        return to_route('workspace.projects.index', ['workspace' => $workspace->slug])
            ->with('success', "Project \"{$project->name}\" created.");
    }

    public function update(
        UpdateProjectRequest $request,
        Workspace $workspace,
        Project $project,
        UpdateProjectAction $action,
    ): RedirectResponse {
        $project = $action->handle($project, $request->toDTO());

        return back()->with('success', "Project \"{$project->name}\" updated.");
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Project $project,
        DeleteProjectAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('delete', $project), 403);

        $name = $project->name;

        $action->handle($project);

        return to_route('workspace.projects.index', ['workspace' => $workspace->slug])
            ->with('success', "Project \"{$name}\" deleted.");
    }
}
