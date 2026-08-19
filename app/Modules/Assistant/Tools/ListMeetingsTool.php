<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Database\Eloquent\Builder;

final class ListMeetingsTool implements AssistantTool
{
    private const MAX_RESULTS = 25;

    private const AGENDA_LIMIT = 160;

    public function name(): string
    {
        return 'list_meetings';
    }

    public function description(): string
    {
        return 'Lists meetings the user can access in the current workspace, with their IDs. '
            .'Use this whenever the user asks about meetings, standups, retros, "my meetings", "what is coming up", '
            .'or names a meeting. Defaults to upcoming meetings; pass scope to look at past or all meetings, '
            .'project_id to narrow to one project, and search to find a meeting by title. '
            .'Never guess a meeting ID — always take it from this tool. '
            .'Do not call this when the user is asking about tasks, sprints or people — it only knows about meetings.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => [
                    'type' => 'string',
                    'enum' => ['upcoming', 'past', 'all'],
                    'description' => 'Which meetings to return. Defaults to upcoming.',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Optional project ID from list_projects, to show only that project\'s meetings.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional case-insensitive filter matched against the meeting title.',
                    'maxLength' => 60,
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->workspace !== null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;
        $user = $context->user;

        if ($workspace === null) {
            return ['success' => false, 'message' => 'No active workspace is selected.'];
        }

        $accessibleProjects = $workspace->accessibleProjectsFor($user)->get(['id', 'name']);
        $projectIds = $accessibleProjects->pluck('id');

        if (isset($args['project_id'])) {
            $projectIds = $projectIds->intersect([(int) $args['project_id']])->values();

            if ($projectIds->isEmpty()) {
                return [
                    'success' => false,
                    'error_code' => 'project_not_found',
                    'error' => 'That project does not exist or you do not have access to it. Use list_projects to see available projects.',
                ];
            }
        }

        $scope = $args['scope'] ?? 'upcoming';

        $query = Meeting::query()
            ->with('project:id,name')
            ->withCount('participants')
            ->whereIn('project_id', $projectIds)
            ->when($scope === 'upcoming', fn (Builder $q) => $q->upcoming())
            ->when($scope === 'past', fn (Builder $q) => $q->past());

        $search = isset($args['search']) ? trim((string) $args['search']) : '';

        if ($search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $total = (clone $query)->count();

        $meetings = $scope === 'past'
            ? $query->orderByDesc('scheduled_at')->limit(self::MAX_RESULTS)->get()
            : $query->orderBy('scheduled_at')->limit(self::MAX_RESULTS)->get();

        return [
            'success' => true,
            'workspace' => ['id' => $workspace->id, 'name' => UntrustedText::inline($workspace->name)],
            'scope' => $scope,
            'total' => $total,
            'returned' => $meetings->count(),
            'truncated' => $total > $meetings->count(),
            'meetings' => $meetings->map(fn (Meeting $meeting) => [
                'id' => $meeting->id,
                'title' => UntrustedText::inline($meeting->title),
                'project_id' => $meeting->project_id,
                'project_name' => UntrustedText::inline($meeting->project->name),
                'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
                'duration_minutes' => $meeting->duration_minutes,
                'agenda' => UntrustedText::block($meeting->description, self::AGENDA_LIMIT),
                'participant_count' => $meeting->participants_count,
                'is_past' => $meeting->scheduled_at->copy()->addMinutes($meeting->duration_minutes)->isPast(),
                'url' => route('workspace.projects.show', [
                    'workspace' => $workspace->slug,
                    'project' => $meeting->project_id,
                ])."?meeting={$meeting->id}",
            ])->all(),
        ];
    }
}
