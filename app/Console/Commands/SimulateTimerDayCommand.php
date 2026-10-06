<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\TimeTracking\Actions\EndLunchAction;
use App\Modules\TimeTracking\Actions\IdlePauseTimerAction;
use App\Modules\TimeTracking\Actions\PauseTimerAction;
use App\Modules\TimeTracking\Actions\ResumeTimerAction;
use App\Modules\TimeTracking\Actions\StartLunchAction;
use App\Modules\TimeTracking\Actions\StartTimerAction;
use App\Modules\TimeTracking\Actions\StopTimerAction;
use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Development and QA only: plays a working day through the real timer
 * actions at chosen times, so lunch, idle pauses and long weeks can be tested
 * without waiting. Each event is "HH:MM action", for example:
 *
 *   --event="09:00 start" --event="13:00 lunch" --event="14:14 lunch-end"
 *   --event="15:10 idle 15:02" (idle pause detected 15:10, last activity 15:02)
 *   --event="15:20 resume" --event="17:00 stop"
 *
 * --days=5 repeats the same day on consecutive dates. Refuses to run in production.
 */
final class SimulateTimerDayCommand extends Command
{
    protected $signature = 'time:simulate-day
        {email : Sign-in email of the person (linked to a Team profile)}
        {project : Project name}
        {date : First day, e.g. 2026-10-12}
        {--event=* : "HH:MM action", actions: start, pause, resume, lunch, lunch-end, idle HH:MM, stop}
        {--days=1 : Repeat on this many consecutive days}
        {--timezone= : Timezone of the times (defaults to the workspace\'s)}';

    protected $description = '[dev/QA only] Play timer events (start, lunch, idle, stop) at chosen times';

    /** The previous event's moment, so a later event at an earlier clock time means "the next day". */
    private ?CarbonImmutable $previous = null;

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('time:simulate-day only runs in local and testing environments.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        $person = $user === null ? null : Person::query()->where('user_id', $user->id)->with('workspace')->first();
        $project = $person === null ? null : Project::query()->where('workspace_id', $person->workspace_id)->where('name', $this->argument('project'))->first();

        if ($person === null || $project === null) {
            $this->error('Need an account linked to a Team profile and a project in that workspace.');

            return self::FAILURE;
        }

        $timezone = $this->option('timezone') ?: $person->workspace->timezone;
        $first = CarbonImmutable::parse((string) $this->argument('date'), $timezone)->startOfDay();

        try {
            for ($day = 0; $day < max(1, (int) $this->option('days')); $day++) {
                $date = $first->addDays($day);
                $this->previous = null;
                $this->line("— {$date->toDateString()}");

                foreach ((array) $this->option('event') as $event) {
                    $this->play($person, $user, $project, $date, (string) $event);
                }
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        return self::SUCCESS;
    }

    private function play(Person $person, User $user, Project $project, CarbonImmutable $date, string $event): void
    {
        [$time, $action, $extra] = array_pad(preg_split('/\s+/', trim($event)) ?: [], 3, null);
        $at = $this->at($date, (string) $time);

        if ($this->previous !== null && $at->lessThan($this->previous)) {
            $at = $at->addDay();
        }

        $this->previous = $at;
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        $key = (string) Str::ulid();
        $session = fn () => TimeSession::query()->where('active_person_id', $person->id)->value('public_id');

        match ($action) {
            'start' => app(StartTimerAction::class)->handle($person, $project, null, true, 'QA simulated day', $key, $user),
            'pause' => app(PauseTimerAction::class)->handle($person, $key),
            'resume' => app(ResumeTimerAction::class)->handle($person, $key),
            'lunch' => app(StartLunchAction::class)->handle($person, $key),
            'lunch-end' => app(EndLunchAction::class)->handle($person, $key),
            'idle' => app(IdlePauseTimerAction::class)->handle($person, $key, $session(), $this->at($at->setTimezone($date->timezone)->startOfDay(), (string) $extra), $at),
            'stop' => app(StopTimerAction::class)->handle($person, $key, $user),
            default => throw new \InvalidArgumentException("Unknown action \"{$action}\" in \"{$event}\"."),
        };

        $this->line("  {$time} {$action}".($extra ? " (idle since {$extra})" : ''));
    }

    /** "HH:MM" on $date in its timezone, as UTC (the app clock). */
    private function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setTime($hour, $minute)->utc();
    }
}
