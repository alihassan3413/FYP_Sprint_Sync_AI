<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Data\HealthSignalData;
use App\Modules\Analytics\Data\HealthVerdict;
use App\Modules\Analytics\Data\ProjectHealthData;
use App\Modules\Analytics\Data\WorkloadEntryData;
use App\Modules\Projects\Actions\BuildSprintReport;
use App\Modules\Projects\Data\SprintHealth;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Collection;

/**
 * Judges how a project is really going, and how fairly the work is spread.
 *
 * Every verdict here is arithmetic, not opinion: the assistant is handed the
 * findings and the numbers behind them so it can explain the situation without
 * having to decide anything itself. A project that is 80% done can still be in
 * trouble when one person holds all of what is left, and that is exactly the
 * kind of thing a completion percentage hides.
 */
final class EvaluateProjectHealth
{
    /**
     * A fixed percentage cannot judge this: with two people on a project one of
     * them always holds at least half, and a 50/50 split is healthy. What marks
     * a real key-person risk is holding most of the open work *and* more of it
     * than everybody else put together.
     */
    private const CONCENTRATION_CRITICAL = 70;

    /** Below this nobody is dominating, whatever the ratio to the runner-up. */
    private const CONCENTRATION_WARNING = 50;

    /** How far ahead of the next busiest person counts as dominating. */
    private const DOMINANCE_MULTIPLE = 2;

    /** Below this there is not enough work for a split to mean anything. */
    private const MIN_OPEN_FOR_CONCENTRATION = 4;

    private const UNASSIGNED_WARNING = 30;

    private const OVERDUE_WARNING = 20;

    private const OVERDUE_CONCENTRATION = 60;

    /** Open work untouched for this long has usually stalled rather than progressed. */
    private const STALE_DAYS = 14;

    private const STALE_WARNING = 25;

    public function __construct(private readonly BuildSprintReport $sprintReport) {}

    public function handle(Project $project): ProjectHealthData
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = $project->tasks()->with(['assignee:id,name', 'boardColumn:id,is_done'])->get();

        $completed = $tasks->filter(fn (Task $task) => $task->completed_at !== null);
        $open = $tasks->reject(fn (Task $task) => $task->completed_at !== null);

        $overdue = $open->filter(
            fn (Task $task) => $task->due_date !== null && $task->due_date->isBefore(now()->startOfDay()),
        );

        $unassigned = $open->filter(fn (Task $task) => $task->assigned_to === null);
        $stale = $open->filter(fn (Task $task) => $task->updated_at?->lt(now()->subDays(self::STALE_DAYS)) === true);

        $workload = $this->workload($tasks, $open, $completed, $overdue);
        $assignedShare = $workload->reject(fn (WorkloadEntryData $entry) => $entry->user_id === null);

        /* Sprint health lives in the sprint report, so it is read rather than re-derived. */
        $sprint = $project->sprints()->active()->first();
        $sprintHealth = $sprint === null
            ? null
            : SprintHealth::from($this->sprintReport->handle($sprint)->health);

        $signals = $this->signals(
            openCount: $open->count(),
            overdueCount: $overdue->count(),
            unassignedCount: $unassigned->count(),
            staleCount: $stale->count(),
            workload: $assignedShare,
            overdueByPerson: $overdue->groupBy('assigned_to'),
            memberCount: $project->members()->count(),
            sprintHealth: $sprintHealth,
        );

        $verdict = HealthVerdict::fromSignals($signals, $tasks->isNotEmpty());
        $busiest = $assignedShare->first();

        return new ProjectHealthData(
            project_id: $project->id,
            project_name: $project->name,
            verdict: $verdict->value,
            verdict_label: $verdict->label(),
            total_tasks: $tasks->count(),
            completed_tasks: $completed->count(),
            open_tasks: $open->count(),
            completion_percentage: $this->percentage($completed->count(), $tasks->count()),
            overdue_tasks: $overdue->count(),
            unassigned_open_tasks: $unassigned->count(),
            stale_open_tasks: $stale->count(),
            people_with_open_work: $assignedShare->filter(fn (WorkloadEntryData $e) => $e->open_tasks > 0)->count(),
            busiest_share_percentage: $busiest?->share_percentage ?? 0,
            active_sprint_name: $sprint?->name,
            active_sprint_health: $sprintHealth?->value,
            signals: $signals,
            workload: $workload->values()->all(),
        );
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, Task>  $open
     * @param  Collection<int, Task>  $completed
     * @param  Collection<int, Task>  $overdue
     * @return Collection<int, WorkloadEntryData>
     */
    private function workload(Collection $tasks, Collection $open, Collection $completed, Collection $overdue): Collection
    {
        $openTotal = $open->count();

        return $tasks
            ->groupBy(fn (Task $task) => $task->assigned_to ?? 0)
            ->map(function (Collection $group, int|string $key) use ($open, $completed, $overdue, $openTotal) {
                $id = (int) $key === 0 ? null : (int) $key;
                $countFor = fn (Collection $set) => $set->where('assigned_to', $id)->count();
                $openCount = $countFor($open);

                return new WorkloadEntryData(
                    user_id: $id,
                    name: $id === null ? 'Unassigned' : ($group->first()->assignee?->name ?? 'Unknown'),
                    open_tasks: $openCount,
                    overdue_tasks: $countFor($overdue),
                    completed_tasks: $countFor($completed),
                    share_percentage: $this->percentage($openCount, $openTotal),
                );
            })
            ->sortByDesc(fn (WorkloadEntryData $entry) => $entry->open_tasks)
            ->values();
    }

    /**
     * @param  Collection<int, WorkloadEntryData>  $workload
     * @param  Collection<int|string, Collection<int, Task>>  $overdueByPerson
     * @return array<int, HealthSignalData>
     */
    private function signals(
        int $openCount,
        int $overdueCount,
        int $unassignedCount,
        int $staleCount,
        Collection $workload,
        Collection $overdueByPerson,
        int $memberCount,
        ?SprintHealth $sprintHealth,
    ): array {
        $signals = [];
        $busiest = $workload->first();

        /*
         * The headline case: one person is the project. Worth flagging even when
         * everything else looks fine, because the risk is invisible in a
         * completion percentage right up until that person is unavailable.
         */
        if ($busiest !== null && $memberCount >= 2 && $openCount >= self::MIN_OPEN_FOR_CONCENTRATION) {
            $others = $workload->skip(1)->sum(fn (WorkloadEntryData $entry) => $entry->open_tasks);
            $runnerUp = $workload->skip(1)->first()?->open_tasks ?? 0;

            $carryingMoreThanEveryoneElse = $busiest->open_tasks > $others;
            $dominatesTheNextPerson = $busiest->open_tasks >= self::DOMINANCE_MULTIPLE * max($runnerUp, 1);

            if ($busiest->share_percentage >= self::CONCENTRATION_CRITICAL && $carryingMoreThanEveryoneElse) {
                $signals[] = HealthSignalData::critical(
                    'workload_concentrated',
                    "{$busiest->name} is carrying this project",
                    "{$busiest->name} holds {$busiest->open_tasks} of the {$openCount} open tasks "
                        ."({$busiest->share_percentage}%), while everyone else holds {$others} between them.",
                    'Move some of their open work to someone with capacity before it becomes a single point of failure.',
                );
            } elseif ($busiest->share_percentage >= self::CONCENTRATION_WARNING && $dominatesTheNextPerson) {
                $signals[] = HealthSignalData::warning(
                    'workload_heavy',
                    "{$busiest->name} is carrying most of the work",
                    "{$busiest->name} holds {$busiest->open_tasks} of the {$openCount} open tasks "
                        ."({$busiest->share_percentage}%); the next busiest has {$runnerUp}.",
                    'Worth rebalancing at the next planning session.',
                );
            }
        }

        $idle = $workload->filter(
            fn (WorkloadEntryData $entry) => $entry->user_id !== null && $entry->open_tasks === 0,
        );

        if ($idle->isNotEmpty() && $busiest !== null && $busiest->open_tasks >= 3) {
            $names = $idle->take(3)->pluck('name')->implode(', ');

            $signals[] = HealthSignalData::note(
                'idle_members',
                'Someone has nothing open',
                "{$names} have no open tasks while {$busiest->name} has {$busiest->open_tasks}.",
                'They are the obvious people to hand work to.',
            );
        }

        if ($overdueCount > 0 && $this->percentage($overdueCount, $openCount) >= self::OVERDUE_WARNING) {
            $share = $this->percentage($overdueCount, $openCount);

            $signals[] = HealthSignalData::warning(
                'overdue_pressure',
                'A lot of work is past its date',
                "{$overdueCount} of the {$openCount} open tasks are overdue ({$share}%).",
                'Re-date what is still real and close what is not.',
            );
        }

        $worstOverdue = $overdueByPerson->reject(fn ($group, $key) => (int) $key === 0)->sortByDesc->count()->take(1);

        if ($overdueCount >= 3 && $worstOverdue->isNotEmpty()) {
            $count = $worstOverdue->first()->count();
            $person = $worstOverdue->first()->first()?->assignee?->name ?? 'One person';

            if ($this->percentage($count, $overdueCount) >= self::OVERDUE_CONCENTRATION) {
                $signals[] = HealthSignalData::warning(
                    'overdue_concentrated',
                    "Most of the overdue work is {$person}'s",
                    "{$person} holds {$count} of the {$overdueCount} overdue tasks.",
                    'Ask what is blocking them rather than adding more.',
                );
            }
        }

        if ($unassignedCount > 0 && $this->percentage($unassignedCount, $openCount) >= self::UNASSIGNED_WARNING) {
            $share = $this->percentage($unassignedCount, $openCount);

            $signals[] = HealthSignalData::warning(
                'unassigned_backlog',
                'A lot of work has no owner',
                "{$unassignedCount} of the {$openCount} open tasks are unassigned ({$share}%).",
                'Unowned work tends not to move. Assign it or drop it.',
            );
        }

        if ($staleCount > 0 && $this->percentage($staleCount, $openCount) >= self::STALE_WARNING) {
            $signals[] = HealthSignalData::warning(
                'stale_work',
                'Work has gone quiet',
                "{$staleCount} open tasks have not been touched in over ".self::STALE_DAYS.' days.',
                'Either they are blocked or they are not really happening.',
            );
        }

        if ($sprintHealth !== null && $sprintHealth->isTrouble()) {
            $signals[] = HealthSignalData::warning(
                'sprint_trouble',
                'The running sprint is behind',
                'The active sprint is '.mb_strtolower($sprintHealth->label()).'.',
                'Ask for the sprint report for the burndown behind this.',
            );
        }

        if ($signals === [] && $openCount > 0) {
            $signals[] = HealthSignalData::note(
                'healthy',
                'Nothing looks wrong',
                'Work is spread across the team, nothing is badly overdue, and nothing has stalled.',
            );
        }

        return $signals;
    }

    private function percentage(int $part, int $total): int
    {
        return $total === 0 ? 0 : (int) round($part / $total * 100);
    }
}
