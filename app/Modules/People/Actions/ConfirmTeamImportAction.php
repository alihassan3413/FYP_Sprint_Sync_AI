<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Data\TeamImportRowStatus;
use App\Modules\People\Data\TeamImportStatus;
use App\Modules\People\Exceptions\TeamImportException;
use App\Modules\People\Jobs\ProcessTeamImportJob;
use App\Modules\People\Models\TeamImport;

final class ConfirmTeamImportAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(TeamImport $import, User $actor): TeamImport
    {
        $confirmed = TeamImport::query()
            ->whereKey($import->getKey())
            ->where('status', TeamImportStatus::Validated)
            ->update([
                'status' => TeamImportStatus::Queued,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($confirmed !== 1) {
            throw TeamImportException::notConfirmable();
        }

        $import->refresh();
        $importing = $import->rows()->whereIn('status', [TeamImportRowStatus::Ready, TeamImportRowStatus::Warning])->count();

        $this->auditLogger->handle(
            $import->workspace,
            null,
            $actor,
            AuditAction::PERSON_IMPORT_CONFIRMED,
            "{$actor->name} started importing {$importing} teammates from {$import->original_name}.",
            $import,
            ['file' => $import->original_name, 'rows' => $import->total_rows, 'importing' => $importing],
        );

        ProcessTeamImportJob::dispatch($import->id);

        return $import;
    }
}
