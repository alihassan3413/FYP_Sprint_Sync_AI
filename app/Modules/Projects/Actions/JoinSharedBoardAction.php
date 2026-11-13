<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * Lets someone add tasks to one board. People from outside the workspace join
 * it as client guests, so they see this project only; existing members keep
 * their workspace role. Workspace admins already have full access, so nothing
 * changes for them. Re-running it is harmless and never downgrades anyone.
 */
final class JoinSharedBoardAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, User $user, User $actor): void
    {
        $workspace = $project->workspace;

        if ($workspace->userHasAtLeast($user, UserRole::ADMIN) || $project->memberCanAddTasks($user)) {
            return;
        }

        DB::transaction(function () use ($project, $workspace, $user): void {
            if (! $workspace->hasMember($user)) {
                $workspace->users()->attach($user->id, ['role' => UserRole::CLIENT->value]);
            }

            if ($project->hasMember($user)) {
                $project->members()->updateExistingPivot($user->id, ['can_add_tasks' => true]);
            } else {
                $project->members()->attach($user->id, ['role' => ProjectRole::MEMBER->value, 'can_add_tasks' => true]);
            }
        });

        $this->auditLogger->handle(
            $workspace,
            $project,
            $actor,
            AuditAction::PROJECT_MEMBER_ADDED,
            $actor->is($user)
                ? "{$user->name} joined the board from its share link and can add tasks."
                : "{$actor->name} let {$user->name} add tasks to the board.",
            $project,
        );
    }
}
