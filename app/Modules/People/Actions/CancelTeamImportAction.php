<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Modules\People\Data\TeamImportStatus;
use App\Modules\People\Exceptions\TeamImportException;
use App\Modules\People\Models\TeamImport;
use Illuminate\Support\Facades\Storage;

final class CancelTeamImportAction
{
    public function handle(TeamImport $import, TeamImportStatus $as = TeamImportStatus::Cancelled): void
    {
        $cancelled = TeamImport::query()
            ->whereKey($import->getKey())
            ->where('status', TeamImportStatus::Validated)
            ->update(['status' => $as, 'path' => null, 'updated_at' => now()]);

        if ($cancelled !== 1) {
            throw TeamImportException::notConfirmable();
        }

        Storage::disk('local')->deleteDirectory("team-imports/{$import->public_id}");
    }
}
