<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\TimeTracking\Actions\StopAbandonedTimerAction;
use App\Modules\TimeTracking\Models\TimeSession;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

final class StopAbandonedTimersCommand extends Command
{
    protected $signature = 'time:stop-abandoned-timers';

    protected $description = 'Stop timers left running past the shift end or the longest allowed run set in each workspace\'s time policy';

    public function handle(StopAbandonedTimerAction $action): int
    {
        $stopped = 0;

        TimeSession::query()
            ->whereNotNull('active_person_id')
            ->with('person.workspace')
            ->chunkById(200, function (Collection $sessions) use ($action, &$stopped) {
                foreach ($sessions as $session) {
                    if ($action->handle($session) !== null) {
                        $stopped++;
                    }
                }
            });

        $this->info("Stopped {$stopped} abandoned ".($stopped === 1 ? 'timer' : 'timers').'.');

        return self::SUCCESS;
    }
}
