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
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Time page: your timer, today / this week / this month and your recent
 * entries. The owner can switch to anyone on the team (?person=), add time
 * for them, correct entries and add previous hours. Days, weeks and months
 * follow the workspace's calendar. While a timer runs past midnight, "today"
 * is that timer's workday (an 18:00–03:00 shift stays on the day it began).
 */
final class TimeIndexController
{
    public function __invoke(Request $request, Workspace $workspace, TimeAccess $access, TimeTotals $totals, TimePolicies $policies, Schedules $schedules): Response
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
        $total = fn (CarbonImmutable $from, ?CarbonImmutable $to = null) => $viewing === null ? 0 : $totals->seconds(new TimeQuery($workspace->id, personId: $viewing->id, from: $from, to: $to ?? $today));
        /* The running timer's workday: yesterday's date at 01:00 during an overnight shift. */
        $workday = $session === null ? $today : CarbonImmutable::parse($schedules->workdayOf($session), $workspace->timezone);
        $schedule = $viewing === null ? null : $schedules->for($viewing);
        $overnightShift = $schedule !== null && $schedule->end_time <= $schedule->start_time ? ['start' => $schedule->start_time, 'end' => $schedule->end_time] : null;

        return Inertia::render('time/index', [
            'canManageTeam' => $canManage,
            'me' => $me?->only(['public_id', 'name']),
            'viewing' => $viewing?->only(['public_id', 'name']),
            'isSelf' => $viewing !== null && $viewing->is($me),
            'people' => $canManage ? $workspace->people()->orderBy('name')->get(['public_id', 'name']) : [],
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
                ->with(['person:id,name,user_id', 'creator:id,name', 'adjusts:id,public_id'])
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
            'reviews' => $canManage ? self::openReviews($workspace) : [],
            'team' => $canManage && ! $request->filled('person') ? $workspace->people()->orderBy('name')->get()
                ->map(fn (Person $person) => ['public_id' => $person->public_id, 'name' => $person->name, ...$totals->week($person, $today)->toArray()])
                ->values() : [],
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
    private static function openReviews(Workspace $workspace): array
    {
        return TimeReview::query()->open()->where('workspace_id', $workspace->id)->with('person:id,name')->orderByDesc('work_date')->limit(50)->get()
            ->map(fn (TimeReview $review) => [
                'public_id' => $review->public_id,
                'kind' => $review->kind->value,
                'person' => $review->person->name,
                'summary' => $review->summary,
                'work_date' => $review->work_date->toDateString(),
                'seconds' => $review->seconds,
                'decisions' => $review->kind->decisions(),
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
        $projects->load(['tasks' => fn ($query) => $query->whereNull('completed_at')->orderBy('title')->select(['id', 'public_id', 'title', 'project_id'])]);

        return $projects->map(fn (Project $project) => [
            'public_id' => $project->public_id,
            'name' => $project->name,
            'tasks' => $project->tasks->map(fn ($task) => ['public_id' => $task->public_id, 'title' => $task->title])->values()->all(),
        ])->values()->all();
    }
}
