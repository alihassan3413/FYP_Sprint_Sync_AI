<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\People\Actions\CancelTeamImportAction;
use App\Modules\People\Data\TeamImportStatus;
use App\Modules\People\Exceptions\TeamImportException;
use App\Modules\People\Models\TeamImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PruneTeamImportsCommand extends Command
{
    protected $signature = 'people:prune-team-imports';

    protected $description = 'Expire unconfirmed teammate imports and delete their uploaded files';

    public function handle(CancelTeamImportAction $cancel): int
    {
        $expired = 0;

        TeamImport::query()
            ->where('status', TeamImportStatus::Validated)
            ->where('created_at', '<', now()->subHours(TeamImport::EXPIRES_AFTER_HOURS))
            ->lazyById()
            ->each(function (TeamImport $import) use ($cancel, &$expired) {
                try {
                    $cancel->handle($import, TeamImportStatus::Expired);
                    $expired++;
                } catch (TeamImportException) {
                }
            });

        $known = TeamImport::query()->whereNotNull('path')->pluck('public_id')->all();
        foreach (Storage::disk('local')->directories('team-imports') as $directory) {
            if (! in_array(basename($directory), $known, true)) {
                Storage::disk('local')->deleteDirectory($directory);
            }
        }

        $this->info("Expired {$expired} unconfirmed import(s).");

        return self::SUCCESS;
    }
}
