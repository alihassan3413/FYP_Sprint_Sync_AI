<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;

final class SetProjectTimezoneAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, ?string $timezone, User $actor): Project
    {
        $timezone = $timezone === '' ? null : $timezone;
        $previous = $project->timezone;

        if ($previous === $timezone) {
            return $project;
        }

        $project->forceFill(['timezone' => $timezone])->save();

        $from = $previous ?? 'workspace default';
        $to = $timezone ?? "workspace default ({$project->effectiveTimezone()})";

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::PROJECT_TIMEZONE_CHANGED,
            "{$actor->name} changed the working timezone of \"{$project->name}\" from {$from} to {$to}.",
            $project,
            ['from' => $previous, 'to' => $timezone],
        );

        return $project;
    }
}
