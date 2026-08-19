<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Data\SprintStatus;
use App\Modules\Projects\Exceptions\SprintException;
use App\Modules\Projects\Models\Sprint;
use Illuminate\Support\Facades\DB;

final class StartSprintAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * Commits the sprint: its current task count becomes the committed scope that
     * everything afterwards is measured against.
     */
    public function handle(Sprint $sprint, User $actor): Sprint
    {
        if (! $sprint->status->canTransitionTo(SprintStatus::Active)) {
            throw SprintException::invalidTransition($sprint->name, $sprint->status, SprintStatus::Active);
        }

        $alreadyActive = Sprint::query()
            ->where('project_id', $sprint->project_id)
            ->active()
            ->whereKeyNot($sprint->id)
            ->first();

        if ($alreadyActive !== null) {
            throw SprintException::anotherSprintIsActive($alreadyActive->name);
        }

        $committed = DB::transaction(function () use ($sprint) {
            $committed = $sprint->tasks()->count();

            $sprint->update([
                'status' => SprintStatus::Active,
                'started_at' => now(),
                'committed_task_count' => $committed,
            ]);

            return $committed;
        });

        $this->auditLogger->handle(
            $sprint->project->workspace,
            $sprint->project,
            $actor,
            AuditAction::SPRINT_STARTED,
            "{$actor->name} started sprint \"{$sprint->name}\" with {$committed} committed ".($committed === 1 ? 'task' : 'tasks').'.',
            $sprint,
            ['committed_task_count' => $committed],
        );

        return $sprint->refresh();
    }
}
