<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Controllers;

use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeEntryData;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Data\TimerData;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Time page: your timer, today / this week / this month and your recent
 * entries. The owner can switch to anyone on the team (?person=), add time
 * for them, correct entries and add previous hours. Days, weeks and months
 * follow the workspace's calendar.
 */
final class TimeIndexController
{
    public function __invoke(Request $request, Workspace $workspace, TimeAccess $access, TimeTotals $totals): Response
    {
        $user = $request->user();
        abort_unless($user->can('track', [TimeEntry::class, $workspace]), 403);

        $canManage = $user->can('manageTeam', [TimeEntry::class, $workspace]);
        $me = $access->personFor($user, $workspace);
        $viewing = $me;

        if ($request->filled('person')) {
            abort_unless($canManage, 403);
            $viewing = $access->person($workspace, $request->string('person')->toString()) ?? abort(404);
        }

        $today = CarbonImmutable::now($workspace->timezone)->startOfDay();
        $session = $viewing === null ? null : TimeSession::query()->where('active_person_id', $viewing->id)->first();
        $total = fn (CarbonImmutable $from) => $viewing === null ? 0 : $totals->seconds(new TimeQuery($workspace->id, personId: $viewing->id, from: $from, to: $today));

        return Inertia::render('time/index', [
            'canManageTeam' => $canManage,
            'me' => $me?->only(['public_id', 'name']),
            'viewing' => $viewing?->only(['public_id', 'name']),
            'isSelf' => $viewing !== null && $viewing->is($me),
            'people' => $canManage ? $workspace->people()->orderBy('name')->get(['public_id', 'name']) : [],
            'timer' => $session === null ? null : TimerData::fromSession($session, now()),
            'projects' => self::projectOptions($access->projects($user, $workspace)),
            'totals' => [
                'today' => $total($today),
                'week' => $total($today->startOfWeek(CarbonInterface::MONDAY)),
                'month' => $total($today->startOfMonth()),
            ],
            'entries' => $viewing === null ? [] : TimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->where('person_id', $viewing->id)
                ->with(['person:id,name,user_id', 'creator:id,name', 'adjusts:id,public_id'])
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(TimeEntryData::fromModel(...))
                ->values(),
            'today' => $today->toDateString(),
            'timezone' => $workspace->timezone,
        ]);
    }

    /**
     * Projects with their open tasks, by public id only.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{public_id: string, name: string, tasks: list<array{public_id: string, title: string}>}>
     */
    public static function projectOptions(Collection $projects): array
    {
        $projects->load(['tasks' => fn ($query) => $query->whereNull('completed_at')->orderBy('title')->select(['id', 'public_id', 'title', 'project_id'])]);

        return $projects->map(fn (Project $project) => [
            'public_id' => $project->public_id,
            'name' => $project->name,
            'tasks' => $project->tasks->map(fn ($task) => ['public_id' => $task->public_id, 'title' => $task->title])->values()->all(),
        ])->values()->all();
    }
}
