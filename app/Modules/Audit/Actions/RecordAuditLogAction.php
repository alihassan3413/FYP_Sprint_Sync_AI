<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Models\User;
use App\Modules\Audit\Data\ActorType;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Data\SystemActor;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordAuditLogAction
{
    /**
     * @param  User|SystemActor|null  $actor  a person, SprintSync's automation, or nobody known
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Workspace $workspace,
        ?Project $project,
        User|SystemActor|null $actor,
        AuditAction $action,
        string $description,
        ?Model $subject = null,
        array $metadata = [],
    ): void {
        $this->write($action, [
            'workspace_id' => $workspace->id,
            'project_id' => $project?->id,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'actor_type' => $actor instanceof SystemActor ? ActorType::System : ActorType::User,
            'action' => $action->value,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function global(
        ?User $actor,
        AuditAction $action,
        string $description,
        array $metadata = [],
    ): void {
        $this->write($action, [
            'workspace_id' => null,
            'project_id' => null,
            'user_id' => $actor?->id,
            'action' => $action->value,
            'subject_type' => $actor !== null ? $actor::class : null,
            'subject_id' => $actor?->getKey(),
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(AuditAction $action, array $attributes): void
    {
        try {
            AuditLog::create($attributes);
        } catch (Throwable $e) {
            Log::error('Audit log entry failed', [
                'action' => $action->value,
                'workspace_id' => $attributes['workspace_id'] ?? null,
                'exception' => $e,
            ]);
        }
    }
}
