<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Data\BoardShareAccess;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Str;

/**
 * Turns the share link on (view only, or view and add tasks) or off. Turning
 * it off keeps the token, so switching back on brings the same link back;
 * regenerating replaces it and the old link stops working at once.
 */
final class UpdateBoardSharingAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, ?BoardShareAccess $access, bool $regenerate, User $actor): Project
    {
        $project->forceFill([
            'share_access' => $access,
            'share_token' => $regenerate || $project->share_token === null ? Str::random(40) : $project->share_token,
        ])->save();

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::PROJECT_UPDATED,
            match (true) {
                $access === null => "{$actor->name} turned off the board's share link.",
                $regenerate => "{$actor->name} replaced the board's share link.",
                $access === BoardShareAccess::Add => "{$actor->name} shared the board: anyone with the link can view it and add tasks.",
                default => "{$actor->name} shared the board: anyone with the link can view it.",
            },
            $project,
        );

        return $project;
    }
}
