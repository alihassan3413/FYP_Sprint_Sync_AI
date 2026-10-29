<?php

declare(strict_types=1);

namespace App\Modules\People\Jobs;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Actions\ProcessTeamImportRowAction;
use App\Modules\People\Data\TeamImportOutcome;
use App\Modules\People\Data\TeamImportRowStatus;
use App\Modules\People\Data\TeamImportStatus;
use App\Modules\People\Models\TeamImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

final class ProcessTeamImportJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public array $backoff = [5, 30, 60];

    public function __construct(public readonly int $importId) {}

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(ProcessTeamImportRowAction $process, RecordAuditLogAction $auditLogger): void
    {
        $import = TeamImport::query()->with('workspace')->find($this->importId);

        if ($import === null || ! in_array($import->status, [TeamImportStatus::Queued, TeamImportStatus::Processing], true)) {
            return;
        }

        $actor = User::query()->find($import->confirmed_by ?? $import->created_by);

        if ($import->status === TeamImportStatus::Queued) {
            $import->forceFill(['status' => TeamImportStatus::Processing])->save();
        }

        $pending = $import->rows()
            ->whereIn('status', [TeamImportRowStatus::Ready, TeamImportRowStatus::Warning])
            ->whereNull('processed_at')
            ->orderBy('row_number');

        if ($actor !== null) {
            foreach ((clone $pending)->limit(TeamImport::CHUNK_SIZE)->get() as $row) {
                $process->handle($row, $import->workspace, $actor);
            }
        } else {
            (clone $pending)->update(['outcome' => TeamImportOutcome::Failed, 'processed_at' => now(), 'updated_at' => now()]);
        }

        $import->forceFill(['processed_rows' => $import->rows()->whereNotNull('processed_at')->count()])->save();

        if ((clone $pending)->exists()) {
            self::dispatch($this->importId);

            return;
        }

        $this->complete($import, $actor, $auditLogger);
    }

    private function complete(TeamImport $import, ?User $actor, RecordAuditLogAction $auditLogger): void
    {
        $finished = TeamImport::query()
            ->whereKey($import->getKey())
            ->where('status', TeamImportStatus::Processing)
            ->update(['status' => TeamImportStatus::Completed, 'completed_at' => now(), 'path' => null, 'updated_at' => now()]);

        if ($finished !== 1) {
            return;
        }

        Storage::disk('local')->deleteDirectory("team-imports/{$import->public_id}");

        $counts = $import->rows()->whereNotNull('outcome')->selectRaw('outcome, count(*) as total')->groupBy('outcome')->pluck('total', 'outcome')->map(fn ($total) => (int) $total)->all();
        $notImported = $import->rows()->whereIn('status', [TeamImportRowStatus::Error, TeamImportRowStatus::Skipped])->count();

        $auditLogger->handle(
            $import->workspace,
            null,
            $actor,
            AuditAction::PERSON_IMPORT_COMPLETED,
            sprintf(
                'Teammate import of %s finished: %d invited, %d linked, %d skipped, %d failed.',
                $import->original_name,
                $counts[TeamImportOutcome::Invited->value] ?? 0,
                $counts[TeamImportOutcome::Linked->value] ?? 0,
                ($counts[TeamImportOutcome::Skipped->value] ?? 0) + $notImported,
                $counts[TeamImportOutcome::Failed->value] ?? 0,
            ),
            $import,
            ['counts' => $counts, 'not_imported' => $notImported],
        );
    }
}
