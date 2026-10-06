<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Actions\RecordActivitySignalAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Development and QA only: sends one window of activity-pattern numbers
 * through the real check, as SprintSync Desktop would, without installing
 * any keep-awake tool. "periodic" imitates a mouse jiggler (a reset every
 * 30 seconds, almost exactly); "human" imitates normal irregular use.
 */
final class SimulateActivityPatternCommand extends Command
{
    protected $signature = 'time:simulate-activity
        {email : Sign-in email of the person (linked to a Team profile)}
        {--pattern=periodic : periodic or human}';

    protected $description = '[dev/QA only] Send a synthetic activity pattern through the integrity check';

    public function handle(RecordActivitySignalAction $action): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('time:simulate-activity only runs in local and testing environments.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        $person = $user === null ? null : Person::query()->where('user_id', $user->id)->with('workspace')->first();

        if ($person === null) {
            $this->error('No Team profile is linked to that account.');

            return self::FAILURE;
        }

        /* Gaps between idle-clock resets, in milliseconds. */
        $gaps = $this->option('pattern') === 'human'
            ? array_map(fn (int $i) => [800, 1200, 45000, 900, 15000, 3000, 120000, 700][$i % 8], range(0, 39))
            : array_map(fn (int $i) => 30000 + ($i % 2 === 0 ? 150 : -150), range(0, 39));

        $mean = intdiv(array_sum($gaps), count($gaps));
        $variance = array_sum(array_map(fn (int $gap) => ($gap - $mean) ** 2, $gaps)) / count($gaps);
        $variationBp = $mean === 0 ? 0 : (int) round(sqrt($variance) / $mean * 10000);
        $end = CarbonImmutable::now();
        $start = $end->subMilliseconds(array_sum($gaps));

        $review = $action->handle($person, $start, $end, count($gaps), $mean, $variationBp);

        $this->info($review === null
            ? 'Nothing unusual (or activity integrity is off). Nothing was recorded.'
            : "Flagged for the owner: \"{$review->summary}\" (resets every ".round($mean / 1000, 1).'s, variation '.round($variationBp / 100, 2).'%).');

        return self::SUCCESS;
    }
}
