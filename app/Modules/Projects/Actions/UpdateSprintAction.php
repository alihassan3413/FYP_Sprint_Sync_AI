<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Data\StoreSprintData;
use App\Modules\Projects\Models\Sprint;

final class UpdateSprintAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Sprint $sprint, User $actor, StoreSprintData $data): Sprint
    {
        $sprint->update([
            'name' => $data->name,
            'goal' => $data->goal,
            'starts_on' => $data->starts_on,
            'ends_on' => $data->ends_on,
        ]);

        if ($sprint->wasChanged()) {
            $this->auditLogger->handle(
                $sprint->project->workspace,
                $sprint->project,
                $actor,
                AuditAction::SPRINT_UPDATED,
                "{$actor->name} updated sprint \"{$sprint->name}\".",
                $sprint,
                ['changed_fields' => array_keys($sprint->getChanges())],
            );
        }

        return $sprint->refresh();
    }
}
