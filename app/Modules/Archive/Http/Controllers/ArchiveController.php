<?php

declare(strict_types=1);

namespace App\Modules\Archive\Http\Controllers;

use App\Modules\Archive\Actions\SearchArchiveAction;
use App\Modules\Archive\Data\ArchiveRecordData;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ArchiveController
{
    public function index(Request $request, Workspace $workspace, SearchArchiveAction $action): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['task', 'meeting'])],
            'project_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $accessibleProjects = $action->accessibleProjects($workspace, $user);

        $results = $action->handle($filters, $accessibleProjects)
            ->through(fn ($row) => ArchiveRecordData::fromRow($row, $this->recordUrl($workspace, $row)));

        return Inertia::render('archive/index', [
            'results' => $results,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'type' => $filters['type'] ?? '',
                'project_id' => $filters['project_id'] ?? null,
                'assignee_id' => $filters['assignee_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'projects' => $accessibleProjects->map(fn ($project) => ['id' => $project->id, 'name' => $project->name])->values(),
            'assignees' => $action->assigneeOptions($accessibleProjects->pluck('id'))
                ->map(fn ($assignee) => ['id' => $assignee->id, 'name' => $assignee->name])
                ->values(),
        ]);
    }

    private function recordUrl(Workspace $workspace, object $row): string
    {
        $base = route('workspace.projects.show', ['workspace' => $workspace->slug, 'project' => $row->project_id]);
        $param = $row->type === 'task' ? 'task' : 'meeting';

        return "{$base}?{$param}={$row->id}";
    }
}
