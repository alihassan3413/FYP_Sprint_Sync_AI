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
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\Schedules;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\TimeTracking\Support\TimePolicies;
use App\Modules\TimeTracking\Support\Timezones;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Time page: your timer, today / this week / this month and your recent
 * entries. The owner, and anyone whose role delegates it, can switch to
 * anyone on the team (?person=); editing (add time for them, correct entries,
 * previous hours) and approving reviews are separate grants. Days, weeks and months
 * follow the workspace's calendar. While a timer runs past midnight, "today"
 * is that timer's workday (an 18:00–03:00 shift stays on the day it began).
 */
final class TimeIndexController
{
    private const array EVIDENCE = [
        'lunch_excess' => ['lunch_seconds', 'allowance_seconds'],
        'auto_stopped' => ['reason', 'stopped_at'],
        'activity' => ['window_start', 'window_end', 'resets', 'mean_gap_seconds', 'variation_percent', 'confidence'],
    ];

    public function __invoke(Request $request, Workspace $workspace, TimeAccess $access, TimeTotals $totals, TimePolicies $policies, Schedules $schedules): Response
    {
        $user = $request->user();
        abort_unless($user->can('track', [TimeEntry::class, $workspace]), 403);

        $canView = $user->can('viewTeam', [TimeEntry::class, $workspace]);
        $canManage = $user->can('manageTeam', [TimeEntry::class, $workspace]);
        $canApprove = $user->can('approveTeam', [TimeEntry::class, $workspace]);
        $me = $access->personFor($user, $workspace);
        $viewing = $me;

        if ($request->filled('person')) {
            abort_unless($canView, 403);
            $viewing = $access->person($workspace, $request->string('person')->toString()) ?? abort(404);
        }

        $today = CarbonImmutable::now($workspace->timezone)->startOfDay();
        $session = $viewing === null ? null : TimeSession::query()->where('active_person_id', $viewing->id)->first();
        $total = fn (CarbonImmutable $from, ?CarbonImmutable $to = null) => $viewing === null ? 0 : $totals->seconds(new TimeQuery($workspace->id, personId: $viewing->id, from: $from, to: $to ?? $today));
        /* The running timer's workday: yesterday's date at 01:00 during an overnight shift. */
        $workday = $session === null ? $today : CarbonImmutable::parse($schedules->workdayOf($session), $workspace->timezone);
        $schedule = $viewing === null ? null : $schedules->for($viewing);
        $overnightShift = $schedule !== null && $schedule->end_time <= $schedule->start_time ? ['start' => $schedule->start_time, 'end' => $schedule->end_time] : null;
        $teamScope = $canView && ! $request->filled('person');

        return Inertia::render('time/index', [
            'canViewTeam' => $canView,
            'canManageTeam' => $canManage,
            'canApproveTime' => $canApprove,
            'canConfigureTime' => $user->can('configure', [TimeEntry::class, $workspace]),
            'scope' => $teamScope ? 'team' : 'person',
            'me' => $me?->only(['public_id', 'name']),
            'viewing' => $viewing?->only(['public_id', 'name']),
            'isSelf' => $viewing !== null && $viewing->is($me),
            'people' => $canView ? $workspace->people()->orderBy('name')->get(['public_id', 'name', 'title']) : [],
            'timer' => $session === null ? null : TimerData::fromSession($session, now()),
            'projects' => self::projectOptions($access->projects($user, $workspace)),
            'totals' => [
                'today' => $total($workday, $workday),
                'week' => $total($today->startOfWeek(CarbonInterface::MONDAY)),
                'month' => $total($today->startOfMonth()),
            ],
            'entries' => $viewing === null ? [] : TimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->where('person_id', $viewing->id)
                ->with(['person:id,name,user_id', 'creator:id,name', 'adjusts:id,public_id', 'session:id,stop_reason'])
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(TimeEntryData::fromModel(...))
                ->values(),
            'today' => $today->toDateString(),
            'timezone' => $workspace->timezone,
            'workday' => [
                'date' => $workday->toDateString(),
                'is_today' => $workday->equalTo($today),
                'timezone_label' => Timezones::label($workspace->timezone),
                'overnight_shift' => $overnightShift,
            ],
            'policy' => $policies->at($workspace->id)->toArray(),
            'breakdown' => $viewing === null ? null : [
                'today' => $totals->breakdown($viewing, $workday, $workday)->toArray(),
                'week' => $totals->week($viewing, $today)->toArray(),
            ],
            'idlePeriods' => $viewing === null ? [] : self::idlePeriods($viewing->id),
            'reviews' => $canApprove && ! $teamScope && $viewing !== null ? self::openReviews($workspace, $viewing) : [],
            'team' => $teamScope ? self::team($workspace, $totals, $today) : [],
            'nextNeedingReview' => $canApprove && ! $teamScope && $viewing !== null ? self::nextNeedingReview($workspace, $viewing) : null,
        ]);
    }

    /**
     * Idle pauses waiting for a decision, and the latest decided ones.
     *
     * @return list<array<string, mixed>>
     */
    public static function idlePeriods(int $personId): array
    {
        return TimeIdlePeriod::query()->where('person_id', $personId)->orderByDesc('started_at')->limit(10)->get()
            ->map(fn (TimeIdlePeriod $period) => [
                'public_id' => $period->public_id,
                'started_at' => $period->started_at->toIso8601String(),
                'ended_at' => $period->ended_at?->toIso8601String(),
                'seconds' => $period->seconds(),
                'threshold_seconds' => $period->threshold_seconds,
                'decision' => $period->decision,
                'reason' => $period->reason,
            ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function team(Workspace $workspace, TimeTotals $totals, CarbonImmutable $today): array
    {
        $openReviews = TimeReview::query()->open()->where('workspace_id', $workspace->id)
            ->selectRaw('person_id, count(*) as open_reviews')->groupBy('person_id')->pluck('open_reviews', 'person_id');

        return $workspace->people()->orderBy('name')->get()
            ->map(fn (Person $person) => [
                'public_id' => $person->public_id,
                'name' => $person->name,
                'open_reviews' => (int) ($openReviews[$person->id] ?? 0),
                ...$totals->week($person, $today)->toArray(),
            ])
            ->sortBy([['open_reviews', 'desc'], ['name', 'asc']])
            ->values()->all();
    }

    /**
     * @return array{public_id: string, name: string, open_reviews: int}|null
     */
    private static function nextNeedingReview(Workspace $workspace, Person $viewing): ?array
    {
        $next = TimeReview::query()->open()->where('workspace_id', $workspace->id)->where('person_id', '!=', $viewing->id)
            ->whereHas('person', fn ($query) => $query->whereNull('removed_at'))
            ->selectRaw('person_id, count(*) as open_reviews')->groupBy('person_id')
            ->orderByDesc('open_reviews')->orderBy('person_id')->with('person:id,public_id,name')->first();

        return $next === null ? null : ['public_id' => $next->person->public_id, 'name' => $next->person->name, 'open_reviews' => (int) $next->open_reviews];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function openReviews(Workspace $workspace, Person $person): array
    {
        return TimeReview::query()->open()->where('workspace_id', $workspace->id)->where('person_id', $person->id)->with('person:id,name')->orderByDesc('work_date')->limit(50)->get()
            ->map(fn (TimeReview $review) => [
                'public_id' => $review->public_id,
                'kind' => $review->kind->value,
                'person' => $review->person->name,
                'summary' => $review->summary,
                'work_date' => $review->work_date->toDateString(),
                'seconds' => $review->seconds,
                'decisions' => $review->kind->decisions(),
                'entry' => $review->details['entry'] ?? null,
                'evidence' => Arr::only($review->details ?? [], self::EVIDENCE[$review->kind->value] ?? []),
            ])->values()->all();
    }

    /**
     * Projects with their open tasks, by public id only.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{public_id: string, name: string, tasks: list<array{public_id: string, title: string}>}>
     */
    public static function projectOptions(Collection $projects): array
    {
        $projects->load([
            'tasks' => fn ($query) => $query->whereNull('completed_at')->orderBy('title')->select(['id', 'public_id', 'title', 'project_id', 'board_column_id']),
            'tasks.boardColumn:id,name',
        ]);

        return $projects->map(fn (Project $project) => [
            'public_id' => $project->public_id,
            'name' => $project->name,
            'tasks' => $project->tasks->map(fn ($task) => ['public_id' => $task->public_id, 'title' => $task->title, 'column' => $task->boardColumn?->name])->values()->all(),
        ])->values()->all();
    }
}
