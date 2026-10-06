<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\TimeTracking\Actions\StartTimerAction;
use App\Modules\TimeTracking\Actions\StopTimerAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Development and QA only: records one timer session at past times (for
 * example 18:05 → 02:03 overnight) through the real Start and Stop actions,
 * as if the clock really read those times. Refuses to run in production.
 */
final class SimulateTimerSessionCommand extends Command
{
    protected $signature = 'time:simulate-session
        {email : Sign-in email of the person tracking (their account must be linked to a Team profile)}
        {project : Project name}
        {from : Start, e.g. "2026-10-12 18:05"}
        {to : Stop, e.g. "2026-10-13 02:03"}
        {--timezone= : Timezone of those times (defaults to the workspace\'s)}';

    protected $description = '[dev/QA only] Record a timer session at chosen past times';

    public function handle(StartTimerAction $start, StopTimerAction $stop): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('time:simulate-session only runs in local and testing environments.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        $person = $user === null ? null : Person::query()->where('user_id', $user->id)->with('workspace')->first();

        if ($person === null) {
            $this->error('No Team profile is linked to that account.');

            return self::FAILURE;
        }

        $project = Project::query()->where('workspace_id', $person->workspace_id)->where('name', $this->argument('project'))->first();

        if ($project === null) {
            $this->error('No project with that name in the workspace.');

            return self::FAILURE;
        }

        $timezone = $this->option('timezone') ?: $person->workspace->timezone;
        $from = CarbonImmutable::parse((string) $this->argument('from'), $timezone);
        $to = CarbonImmutable::parse((string) $this->argument('to'), $timezone);

        /* The clock runs in UTC like the app; a local-time "now" would be stored shifted. */
        Carbon::setTestNow($from->utc());
        $start->handle($person, $project, null, true, 'QA simulated session', (string) Str::ulid(), $user);
        Carbon::setTestNow($to->utc());
        $session = $stop->handle($person, (string) Str::ulid(), $user);
        Carbon::setTestNow();

        $entry = $session->entry;
        $this->info(sprintf(
            'Recorded %dh %02dm for %s on %s, filed under %s (%s).',
            intdiv($entry->duration_seconds, 3600),
            intdiv($entry->duration_seconds % 3600, 60),
            $person->name,
            $project->name,
            $entry->period_start->toDateString(),
            $entry->timezone,
        ));

        return self::SUCCESS;
    }
}
