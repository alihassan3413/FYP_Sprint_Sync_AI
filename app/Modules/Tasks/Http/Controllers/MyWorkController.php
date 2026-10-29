<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Controllers;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Http\Requests\MyWorkRequest;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class MyWorkController
{
    public const PER_PAGE = 50;

    public function __invoke(MyWorkRequest $request, Workspace $workspace): Response
    {
        $user = $request->user();
        $canViewAll = $workspace->userHasAtLeast($user, UserRole::ADMIN);
        $filters = $request->filters($canViewAll);

        $projects = $workspace->accessibleProjectsFor($user)
            ->orderBy('name')
            ->get(['id', 'name', 'workspace_id'])
            ->each->setRelation('workspace', $workspace)
            ->filter(fn (Project $project) => $user->can('viewAny', [Task::class, $project]))
            ->values();

        $projectIds = $filters['project'] !== null && $projects->contains('id', $filters['project'])
            ? [$filters['project']]
            : ($filters['project'] !== null ? [] : $projects->pluck('id')->all());

        $today = CarbonImmutable::now($workspace->timezone)->startOfDay();

        $tasks = Task::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('project_id', $projectIds)
            ->with(['project:id,name', 'boardColumn:id,name,is_done', 'assignee:id,name', 'sprint:id,name'])
            ->when($filters['scope'] === 'mine', fn (Builder $query) => $query->where('assigned_to', $user->id))
            ->when($filters['assignee'] === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when($filters['assignee'] !== null && $filters['assignee'] !== 'unassigned', fn (Builder $query) => $query->where('assigned_to', (int) $filters['assignee']))
            ->when($filters['status'] === 'open', fn (Builder $query) => $query->whereHas('boardColumn', fn (Builder $column) => $column->where('is_done', false)))
            ->when($filters['status'] === 'done', fn (Builder $query) => $query->whereHas('boardColumn', fn (Builder $column) => $column->where('is_done', true)))
            ->when($filters['due'] === 'overdue', fn (Builder $query) => $query->whereNotNull('due_date')->whereDate('due_date', '<', $today->toDateString())->whereHas('boardColumn', fn (Builder $column) => $column->where('is_done', false)))
            ->when($filters['due'] === 'today', fn (Builder $query) => $query->whereDate('due_date', $today->toDateString()))
            ->when($filters['due'] === 'week', fn (Builder $query) => $query->whereDate('due_date', '>=', $today->toDateString())->whereDate('due_date', '<=', $today->addDays(6)->toDateString()))
            ->when($filters['due'] === 'none', fn (Builder $query) => $query->whereNull('due_date'))
            ->when($filters['search'] !== null, fn (Builder $query) => $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%'))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'project' => ['id' => $task->project_id, 'name' => $task->project->name],
                'column' => ['name' => $task->boardColumn->name, 'is_done' => $task->boardColumn->is_done],
                'assignee' => $task->assignee === null ? null : ['id' => $task->assignee->id, 'name' => $task->assignee->name],
                'sprint' => $task->sprint?->name,
                'due_date' => $task->due_date?->toDateString(),
                'is_overdue' => $task->due_date !== null && ! $task->boardColumn->is_done && $task->due_date->toDateString() < $today->toDateString(),
                'url' => route('workspace.projects.show', ['workspace' => $workspace->slug, 'project' => $task->project_id, 'task' => $task->id]),
            ]);

        return Inertia::render('my-work/index', [
            'tasks' => $tasks,
            'filters' => $filters,
            'canViewAll' => $canViewAll,
            'projects' => $projects->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name])->values(),
            'assignees' => $canViewAll ? $this->assignees($workspace, $projects) : [],
            'today' => $today->toDateString(),
        ]);
    }

    private function assignees(Workspace $workspace, Collection $projects): array
    {
        return $workspace->users()
            ->whereIn('users.id', Task::query()->whereIn('project_id', $projects->pluck('id'))->whereNotNull('assigned_to')->select('assigned_to'))
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => Str::limit($user->name, 60)])
            ->values()
            ->all();
    }
}
