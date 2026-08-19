<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\SprintHealth;
use App\Modules\Projects\Data\SprintReportData;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Collection;

/**
 * Turns a sprint and its tasks into the numbers a standup actually needs:
 * where the work stands, whether that is good enough for the day it is, and
 * what to do about it.
 */
final class BuildSprintReport
{
    private const MAX_BURNDOWN_POINTS = 60;

    private const MAX_BLOCKERS = 5;

    private const VELOCITY_SAMPLE = 3;

    public function handle(Sprint $sprint): SprintReportData
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = $sprint->tasks()
            ->with(['assignee:id,name', 'boardColumn:id,name,is_done'])
            ->get();

        $total = $tasks->count();
        $completed = $tasks->filter(fn (Task $task) => $task->isCompleted())->count();
        $open = $total - $completed;

        /*
         * A completed sprint reports the numbers frozen when it closed, not what its
         * surviving tasks look like today — carried-over work has left it by now, and
         * editing an old task must not rewrite history.
         */
        if ($sprint->status->isCompleted() && $sprint->completed_task_count !== null) {
            $completed = $sprint->completed_task_count;
            $open = $sprint->carried_over_task_count ?? 0;
            $total = $sprint->committed_task_count ?? ($completed + $open);
        }

        $today = now()->startOfDay();
        $overdueTasks = $tasks
            ->filter(fn (Task $task) => ! $task->isCompleted()
                && $task->due_date !== null
                && $task->due_date->lessThan($today))
            ->values();

        $unassigned = $tasks->filter(fn (Task $task) => ! $task->isCompleted() && $task->assigned_to === null)->count();

        $completionPercentage = $this->percentage($completed, $total);
        $expectedPercentage = $this->expectedPercentage($sprint);
        $paceDelta = $completionPercentage - $expectedPercentage;

        $health = $this->health($sprint, $total, $completionPercentage, $paceDelta);
        $scopeAdded = $sprint->committed_task_count === null ? 0 : max(0, $total - $sprint->committed_task_count);

        return new SprintReportData(
            sprint_id: $sprint->id,
            name: $sprint->name,
            goal: $sprint->goal,
            status: $sprint->status->value,
            health: $health->value,
            health_label: $health->label(),
            starts_on: $sprint->starts_on->toDateString(),
            ends_on: $sprint->ends_on->toDateString(),
            total_days: $sprint->totalDays(),
            days_elapsed: $sprint->elapsedDays(),
            days_remaining: $sprint->daysRemaining(),
            time_elapsed_percentage: $sprint->timeElapsedPercentage(),
            total_tasks: $total,
            completed_tasks: $completed,
            open_tasks: $open,
            overdue_tasks: $overdueTasks->count(),
            unassigned_tasks: $unassigned,
            completion_percentage: $completionPercentage,
            expected_percentage: $expectedPercentage,
            pace_delta: $paceDelta,
            committed_task_count: $sprint->committed_task_count,
            scope_added: $scopeAdded,
            carried_over_task_count: $sprint->carried_over_task_count,
            average_cycle_time_days: $this->averageCycleTime($tasks),
            velocity_average: $this->velocityAverage($sprint),
            burndown: $this->burndown($sprint, $tasks),
            workload: $this->workload($tasks),
            blockers: $this->blockers($overdueTasks),
            column_breakdown: $this->columnBreakdown($tasks),
            recommendations: $this->recommendations($sprint, $health, $total, $open, $unassigned, $overdueTasks->count(), $scopeAdded, $completionPercentage),
            summary: $this->summary($sprint, $health, $total, $completed, $paceDelta),
        );
    }

    private function percentage(int $part, int $whole): int
    {
        return $whole === 0 ? 0 : (int) round(($part / $whole) * 100);
    }

    /**
     * Where completion "should" be if work burned down evenly across the sprint.
     */
    private function expectedPercentage(Sprint $sprint): int
    {
        return match (true) {
            $sprint->status->isPlanned() => 0,
            $sprint->status->isCompleted() => 100,
            default => $sprint->timeElapsedPercentage(),
        };
    }

    private function health(Sprint $sprint, int $total, int $completionPercentage, int $paceDelta): SprintHealth
    {
        if ($sprint->status->isCompleted()) {
            return SprintHealth::Done;
        }

        if ($sprint->status->isPlanned()) {
            return SprintHealth::NotStarted;
        }

        if ($total === 0) {
            return SprintHealth::Empty;
        }

        if ($sprint->isOverdue() && $completionPercentage < 100) {
            return SprintHealth::Overdue;
        }

        return match (true) {
            $paceDelta >= -SprintHealth::AT_RISK_GAP => SprintHealth::OnTrack,
            $paceDelta >= -SprintHealth::OFF_TRACK_GAP => SprintHealth::AtRisk,
            default => SprintHealth::OffTrack,
        };
    }

    /**
     * Remaining open tasks at the end of each day, against the straight line the
     * sprint would follow if it burned down evenly. Built from tasks.completed_at,
     * so it is exact for any day already gone by.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array{date: string, remaining: int, ideal: float}>
     */
    private function burndown(Sprint $sprint, Collection $tasks): array
    {
        $start = $sprint->starts_on->copy()->startOfDay();
        $lastDay = $sprint->ends_on->copy()->startOfDay();
        $today = now()->startOfDay();

        /* A running sprint only has real data up to today; a finished one has all of it. */
        $through = $sprint->status->isCompleted() ? $lastDay : min($lastDay, $today);

        if ($through->lessThan($start)) {
            return [];
        }

        $totalDays = $sprint->totalDays();
        $scopeBase = $sprint->committed_task_count ?? $tasks->count();
        $points = [];
        $cursor = $start->copy();
        $index = 0;

        while ($cursor->lessThanOrEqualTo($through) && count($points) < self::MAX_BURNDOWN_POINTS) {
            $endOfDay = $cursor->copy()->endOfDay();

            $remaining = $tasks->filter(function (Task $task) use ($endOfDay) {
                $existedYet = $task->created_at === null || $task->created_at->lessThanOrEqualTo($endOfDay);
                $stillOpen = $task->completed_at === null || $task->completed_at->greaterThan($endOfDay);

                return $existedYet && $stillOpen;
            })->count();

            $points[] = [
                'date' => $cursor->toDateString(),
                'remaining' => $remaining,
                'ideal' => $totalDays <= 1
                    ? 0.0
                    : round($scopeBase * (1 - ($index / ($totalDays - 1))), 2),
            ];

            $cursor->addDay();
            $index++;
        }

        return $points;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array{name: string, total: int, completed: int}>
     */
    private function workload(Collection $tasks): array
    {
        return $tasks
            ->groupBy(fn (Task $task) => $task->assignee?->name ?? 'Unassigned')
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'total' => $group->count(),
                'completed' => $group->filter(fn (Task $task) => $task->isCompleted())->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Task>  $overdueTasks
     * @return array<int, array{id: int, title: string, due_date: string|null, assignee: string|null}>
     */
    private function blockers(Collection $overdueTasks): array
    {
        return $overdueTasks
            ->sortBy(fn (Task $task) => $task->due_date)
            ->take(self::MAX_BLOCKERS)
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(),
                'assignee' => $task->assignee?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<string, int>
     */
    private function columnBreakdown(Collection $tasks): array
    {
        return $tasks
            ->groupBy(fn (Task $task) => $task->boardColumn?->name ?? 'Unknown')
            ->map(fn (Collection $group) => $group->count())
            ->all();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     */
    private function averageCycleTime(Collection $tasks): ?float
    {
        $cycleTimes = $tasks
            ->filter(fn (Task $task) => $task->isCompleted())
            ->map(fn (Task $task) => $task->cycleTimeInDays())
            ->filter(fn (?int $days) => $days !== null);

        return $cycleTimes->isEmpty() ? null : round((float) $cycleTimes->avg(), 1);
    }

    /**
     * Tasks per sprint over the project's recent finished sprints.
     */
    private function velocityAverage(Sprint $sprint): ?float
    {
        $recent = Sprint::query()
            ->where('project_id', $sprint->project_id)
            ->completed()
            ->whereKeyNot($sprint->id)
            ->whereNotNull('completed_task_count')
            ->orderByDesc('completed_at')
            ->limit(self::VELOCITY_SAMPLE)
            ->pluck('completed_task_count');

        return $recent->isEmpty() ? null : round((float) $recent->avg(), 1);
    }

    /**
     * @return array<int, string>
     */
    private function recommendations(
        Sprint $sprint,
        SprintHealth $health,
        int $total,
        int $open,
        int $unassigned,
        int $overdue,
        int $scopeAdded,
        int $completionPercentage,
    ): array {
        $notes = [];

        if ($sprint->status->isPlanned()) {
            $notes[] = $total === 0
                ? 'Add tasks to this sprint before starting it.'
                : "Ready to start with {$total} ".($total === 1 ? 'task' : 'tasks').'.';
        }

        if ($sprint->status->isActive()) {
            if ($total === 0) {
                $notes[] = 'This sprint is running with no tasks in it.';
            }

            if ($completionPercentage === 100 && $total > 0) {
                $notes[] = 'Everything is done — complete the sprint to lock in the result.';
            }

            if ($sprint->isOverdue()) {
                $notes[] = "The end date has passed with {$open} ".($open === 1 ? 'task' : 'tasks')
                    .' still open. Complete the sprint and carry them over.';
            } elseif ($health->isTrouble()) {
                $notes[] = "Behind the burn rate with {$open} open and {$sprint->daysRemaining()} "
                    .($sprint->daysRemaining() === 1 ? 'day' : 'days').' left. Drop scope or add help.';
            }

            if ($unassigned > 0) {
                $notes[] = "{$unassigned} open ".($unassigned === 1 ? 'task has' : 'tasks have').' nobody assigned.';
            }

            if ($overdue > 0) {
                $notes[] = "{$overdue} ".($overdue === 1 ? 'task is' : 'tasks are').' past their due date.';
            }

            if ($scopeAdded > 0) {
                $notes[] = "Scope grew by {$scopeAdded} ".($scopeAdded === 1 ? 'task' : 'tasks').' after the sprint started.';
            }
        }

        if ($sprint->status->isCompleted()) {
            $notes[] = "Finished with {$sprint->completed_task_count} done"
                .($sprint->committed_task_count !== null ? " of {$sprint->committed_task_count} committed" : '')
                .($sprint->carried_over_task_count ? ", {$sprint->carried_over_task_count} carried over." : '.');
        }

        return $notes;
    }

    private function summary(Sprint $sprint, SprintHealth $health, int $total, int $completed, int $paceDelta): string
    {
        if ($sprint->status->isPlanned()) {
            return "\"{$sprint->name}\" is planned with {$total} ".($total === 1 ? 'task' : 'tasks')
                .", starting {$sprint->starts_on->toFormattedDateString()}.";
        }

        if ($sprint->status->isCompleted()) {
            return "\"{$sprint->name}\" closed with {$sprint->completed_task_count} of "
                .($sprint->committed_task_count ?? $total).' committed tasks done.';
        }

        $pace = match (true) {
            $paceDelta > 5 => 'ahead of schedule',
            $paceDelta >= -SprintHealth::AT_RISK_GAP => 'roughly on schedule',
            default => 'behind schedule',
        };

        return "\"{$sprint->name}\" is {$health->label()}: {$completed} of {$total} tasks done with "
            ."{$sprint->daysRemaining()} ".($sprint->daysRemaining() === 1 ? 'day' : 'days')." left, {$pace}.";
    }
}
