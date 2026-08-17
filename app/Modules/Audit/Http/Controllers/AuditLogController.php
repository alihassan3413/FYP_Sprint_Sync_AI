<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Actions\SearchAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Data\AuditLogEntryData;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController
{
    public function index(Request $request, Workspace $workspace, SearchAuditLogAction $action): Response
    {
        $user = $request->user();

        abort_unless($user->can('viewAny', [AuditLog::class, $workspace]), 403);

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(AuditAction::categories())],
            'project_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $entries = $action->handle($workspace, $user, $filters)
            ->through(AuditLogEntryData::fromModel(...));

        return Inertia::render('audit/index', [
            'entries' => $entries,
            'filters' => [
                'user_id' => $filters['user_id'] ?? null,
                'category' => $filters['category'] ?? '',
                'project_id' => $filters['project_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'projects' => $action->visibleProjects($workspace, $user)->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
            ])->values(),
            'actors' => $action->actorOptions($workspace, $user)->map(fn ($actor) => [
                'id' => $actor->id,
                'name' => $actor->name,
            ])->values(),
            'categories' => AuditAction::categories(),
            'isAdmin' => $workspace->userHasAtLeast($user, UserRole::ADMIN),
        ]);
    }
}
