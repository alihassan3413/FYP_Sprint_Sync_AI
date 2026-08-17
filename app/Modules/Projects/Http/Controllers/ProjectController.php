<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Models\User;
use App\Modules\Meetings\Data\MeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Actions\CreateProjectAction;
use App\Modules\Projects\Actions\DeleteProjectAction;
use App\Modules\Projects\Actions\UpdateProjectAction;
use App\Modules\Projects\Data\ProjectData;
use App\Modules\Projects\Http\Requests\StoreProjectRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Data\BoardColumnData;
use App\Modules\Tasks\Data\TaskData;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProjectController
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        $projects = $workspace->accessibleProjectsFor($user)->latest()->get();

        return Inertia::render('projects/index', [
            'projects' => $projects->map(ProjectData::fromModel(...))->values(),
            'canManageProjects' => $user->can('create', [Project::class, $workspace]),
        ]);
    }

    public function show(Request $request, Workspace $workspace, Project $project): Response
    {
        $user = $request->user();

        abort_unless($user->can('view', $project), 403);

        $projectMemberIds = $project->members()->pluck('users.id');

        return Inertia::render('projects/show', [
            'project' => ProjectData::fromModel($project),
            'canManageProjects' => $user->can('update', $project),
            'canManageTasks' => $user->can('create', [Task::class, $project]),
            'canManageMeetings' => $user->can('create', [Meeting::class, $project]),
            'canManageProjectMembers' => $user->can('manageMembers', $project),
            'canManageBoardColumns' => $user->can('create', [BoardColumn::class, $project]),
            'boardColumns' => $project->boardColumns()
                ->orderBy('position')
                ->get()
                ->map(BoardColumnData::fromModel(...))
                ->values(),
            'tasks' => $project->tasks()
                ->with(['assignee:id,name,email', 'comments.user:id,name,email'])
                ->latest()
                ->get()
                ->map(TaskData::fromModel(...))
                ->values(),
            'meetings' => $project->meetings()
                ->with('creator:id,name,email')
                ->orderBy('scheduled_at')
                ->get()
                ->map(MeetingData::fromModel(...))
                ->values(),
            'members' => $workspace->users()
                ->select('users.id', 'users.name', 'users.email', 'workspace_users.role as workspace_role')
                ->get()
                ->filter(fn (User $member) => in_array($member->workspace_role, [UserRole::OWNER->value, UserRole::ADMIN->value], true)
                    || $projectMemberIds->contains($member->id))
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                ])
                ->sortBy('name')
                ->values(),
            'projectMembers' => $project->members()
                ->select('users.id', 'users.name', 'users.email')
                ->orderBy('users.name')
                ->get()
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                ])
                ->values(),
            'workspaceMembers' => $workspace->users()
                ->select('users.id', 'users.name', 'users.email')
                ->orderBy('users.name')
                ->get()
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                ])
                ->values(),
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        Workspace $workspace,
        CreateProjectAction $action,
    ): RedirectResponse {
        $project = $action->handle($workspace, $request->toDTO(), $request->user());

        return to_route('workspace.projects.index', ['workspace' => $workspace->slug])
            ->with('success', "Project \"{$project->name}\" created.");
    }

    public function update(
        UpdateProjectRequest $request,
        Workspace $workspace,
        Project $project,
        UpdateProjectAction $action,
    ): RedirectResponse {
        $project = $action->handle($project, $request->toDTO(), $request->user());

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

        $action->handle($project, $request->user());

        return to_route('workspace.projects.index', ['workspace' => $workspace->slug])
            ->with('success', "Project \"{$name}\" deleted.");
    }
}
