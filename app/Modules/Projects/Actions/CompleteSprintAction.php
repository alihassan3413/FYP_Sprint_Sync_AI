<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Data\SprintCarryOver;
use App\Modules\Projects\Data\SprintStatus;
use App\Modules\Projects\Exceptions\SprintException;
use App\Modules\Projects\Models\Sprint;
use Illuminate\Support\Facades\DB;

final class CompleteSprintAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * Closes the sprint, freezes its numbers, and decides where unfinished work goes.
     * Nothing is deleted: carried-over tasks keep their board position, they just
     * change (or lose) their sprint.
     */
    public function handle(
        Sprint $sprint,
        User $actor,
        SprintCarryOver $carryOver = SprintCarryOver::Backlog,
        ?Sprint $carryOverTarget = null,
    ): Sprint {
        if (! $sprint->status->canTransitionTo(SprintStatus::Completed)) {
            throw SprintException::invalidTransition($sprint->name, $sprint->status, SprintStatus::Completed);
        }

        $target = $carryOver === SprintCarryOver::NextSprint
            ? $this->resolveTarget($sprint, $carryOverTarget)
            : null;

        DB::transaction(function () use ($sprint, $target) {
            $completed = $sprint->tasks()->completed()->count();
            $unfinished = $sprint->tasks()->open()->get();

            $sprint->tasks()->open()->update(['sprint_id' => $target?->id]);

            $sprint->update([
                'status' => SprintStatus::Completed,
                'completed_at' => now(),
                'completed_task_count' => $completed,
                'carried_over_task_count' => $unfinished->count(),
            ]);
        });

        $sprint->refresh();

        $destination = $target === null
            ? 'the backlog'
            : "\"{$target->name}\"";

        $this->auditLogger->handle(
            $sprint->project->workspace,
            $sprint->project,
            $actor,
            AuditAction::SPRINT_COMPLETED,
            "{$actor->name} completed sprint \"{$sprint->name}\": {$sprint->completed_task_count} done, "
                ."{$sprint->carried_over_task_count} moved to {$destination}.",
            $sprint,
            [
                'completed_task_count' => $sprint->completed_task_count,
                'carried_over_task_count' => $sprint->carried_over_task_count,
                'carried_over_to' => $target?->id,
            ],
        );

        return $sprint;
    }

    private function resolveTarget(Sprint $sprint, ?Sprint $carryOverTarget): ?Sprint
    {
        $target = $carryOverTarget ?? Sprint::query()
            ->where('project_id', $sprint->project_id)
            ->planned()
            ->whereKeyNot($sprint->id)
            ->orderBy('starts_on')
            ->first();

        if ($target === null) {
            return null;
        }

        if ($target->project_id !== $sprint->project_id || ! $target->status->isPlanned()) {
            throw SprintException::carryOverTargetInvalid();
        }

        return $target;
    }
}
