<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordAuditLogAction
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Workspace $workspace,
        ?Project $project,
        ?User $actor,
        AuditAction $action,
        string $description,
        ?Model $subject = null,
        array $metadata = [],
    ): void {
        try {
            AuditLog::create([
                'workspace_id' => $workspace->id,
                'project_id' => $project?->id,
                'user_id' => $actor?->id,
                'action' => $action->value,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'description' => $description,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $e) {
            Log::error('Audit log entry failed', [
                'action' => $action->value,
                'workspace_id' => $workspace->id,
                'exception' => $e,
            ]);
        }
    }
}
