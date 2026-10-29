<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\TimeTracking\Actions\SaveWorkScheduleAction;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\Feature\TimeTracking\Concerns\ConfiguresTimePolicy;
use Tests\TestCase;

final class WorkingTimezoneTest extends TestCase
{
    use BuildsTimeTeam, ConfiguresTimePolicy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00', 'Asia/Karachi')->utc());
        $this->setUpTimeTeam('Asia/Karachi');
    }

    private function at(string $local, string $timezone): void
    {
        $this->travelTo(CarbonImmutable::parse($local, $timezone)->utc());
    }

    private function setProjectTimezone(?string $timezone, ?Project $project = null): TestResponse
    {
        $project ??= $this->project;

        return $this->actingAs($this->owner)
            ->from(route('workspace.projects.show', [$this->workspace, $project->id]))
            ->put(route('workspace.projects.update', [$this->workspace, $project->id]), [
                'name' => $project->name,
                'description' => $project->description,
                'timezone' => $timezone,
            ]);
    }

    private function track(string $start, string $stop, string $timezone): TimeEntry
    {
        $this->at($start, $timezone);
        $this->startTimer()->assertOk();
        $this->at($stop, $timezone);
        $this->timer('stop')->assertOk();

        return TimeEntry::query()->where('source', TimeEntrySource::Tracked->value)->latest('id')->firstOrFail();
    }

    public function test_a_project_inherits_the_workspace_timezone_until_it_overrides_it(): void
    {
        $this->assertNull($this->project->timezone);
        $this->assertSame('Asia/Karachi', $this->project->effectiveTimezone());

        $this->workspace->forceFill(['timezone' => 'Europe/London'])->save();
        $this->assertSame('Europe/London', $this->project->fresh()->effectiveTimezone());

        $this->setProjectTimezone('America/New_York')->assertSessionHasNoErrors();
        $this->assertSame('America/New_York', $this->project->fresh()->effectiveTimezone());

        $this->setProjectTimezone('')->assertSessionHasNoErrors();
        $this->assertNull($this->project->fresh()->timezone);
        $this->assertSame('Europe/London', $this->project->fresh()->effectiveTimezone());
    }

    public function test_the_app_timezone_is_the_last_resort(): void
    {
        $orphan = new Project(['name' => 'Detached']);

        $this->assertSame(config('app.timezone'), $orphan->effectiveTimezone());
    }

    public function test_the_project_timezone_setting_is_validated_audited_and_kept_when_omitted(): void
    {
        $this->setProjectTimezone('Mars/Olympus')->assertSessionHasErrors('timezone');
        $this->assertNull($this->project->fresh()->timezone);

        $this->setProjectTimezone('America/New_York');
        $log = AuditLog::query()->where('action', AuditAction::PROJECT_TIMEZONE_CHANGED->value)->sole();
        $this->assertStringContainsString('from workspace default to America/New_York', $log->description);

        $this->actingAs($this->owner)->put(route('workspace.projects.update', [$this->workspace, $this->project->id]), ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame('America/New_York', $this->project->fresh()->timezone, 'editing other details never clears the override');

        $this->setProjectTimezone('America/New_York');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PROJECT_TIMEZONE_CHANGED->value)->count(), 'no change, no entry');
    }

    public function test_only_people_who_manage_the_project_can_change_its_timezone(): void
    {
        $this->actingAs($this->member)->put(route('workspace.projects.update', [$this->workspace, $this->project->id]), ['name' => $this->project->name, 'timezone' => 'America/New_York'])->assertForbidden();

        $this->assertNull($this->project->fresh()->timezone);
    }

    public function test_a_timer_follows_the_projects_working_day(): void
    {
        $this->setProjectTimezone('America/New_York');

        $entry = $this->track('2026-10-09 22:00', '2026-10-09 23:30', 'America/New_York');

        $this->assertSame('America/New_York', $entry->timezone);
        $this->assertSame('2026-10-09', $entry->period_start->toDateString(), '22:00 in New York is already the 10th in Karachi');
    }

    public function test_a_project_without_an_override_keeps_the_workspace_day(): void
    {
        $entry = $this->track('2026-10-10 07:00', '2026-10-10 08:00', 'Asia/Karachi');

        $this->assertSame('Asia/Karachi', $entry->timezone);
        $this->assertSame('2026-10-10', $entry->period_start->toDateString());
    }

    public function test_an_overnight_timer_in_the_project_timezone_across_the_dst_change(): void
    {
        $this->setProjectTimezone('America/New_York');

        $this->at('2026-10-31 22:00', 'America/New_York');
        $this->startTimer();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'));
        $this->timer('stop');

        $entry = TimeEntry::query()->sole();
        $this->assertSame('2026-10-31', $entry->period_start->toDateString());
        $this->assertSame(4 * 3600 + 30 * 60, $entry->duration_seconds, '22:00 EDT to 01:30 EST is 4h30 of real time across the repeated hour');
    }

    public function test_a_persons_overnight_schedule_still_decides_their_shift(): void
    {
        $this->setProjectTimezone('America/New_York');
        app(SaveWorkScheduleAction::class)->handle($this->workspace, $this->usman(), [1, 2, 3, 4, 5], '18:00', '03:00', 'Asia/Karachi', $this->owner);

        $this->track('2026-10-08 18:00', '2026-10-08 23:00', 'Asia/Karachi');
        $this->track('2026-10-09 00:40', '2026-10-09 02:00', 'Asia/Karachi');

        $this->assertSame(['2026-10-08', '2026-10-08'], TimeEntry::query()->orderBy('id')->get()->map(fn (TimeEntry $entry) => $entry->period_start->toDateString())->all());
    }

    public function test_a_running_timer_keeps_the_timezone_it_started_with(): void
    {
        $this->setProjectTimezone('America/New_York');
        $this->at('2026-10-09 22:00', 'America/New_York');
        $this->startTimer();

        $this->setProjectTimezone('Asia/Karachi');
        $this->workspace->forceFill(['timezone' => 'Europe/London'])->save();
        $this->at('2026-10-09 23:00', 'America/New_York');
        $this->timer('stop');

        $entry = TimeEntry::query()->sole();
        $this->assertSame(['America/New_York', '2026-10-09'], [$entry->timezone, $entry->period_start->toDateString()]);
    }

    public function test_changing_timezones_never_reclassifies_recorded_time(): void
    {
        $entry = $this->track('2026-10-09 01:00', '2026-10-09 02:00', 'Asia/Karachi');
        $before = [$entry->period_start->toDateString(), $entry->timezone, $entry->duration_seconds];

        $this->setProjectTimezone('America/New_York');
        $this->workspace->forceFill(['timezone' => 'Pacific/Auckland'])->save();

        $entry->refresh();
        $this->assertSame($before, [$entry->period_start->toDateString(), $entry->timezone, $entry->duration_seconds]);
        $this->assertSame(3600, app(TimeTotals::class)->seconds(new TimeQuery($this->workspace->id, personId: $this->usman()->id, from: CarbonImmutable::parse('2026-10-09'), to: CarbonImmutable::parse('2026-10-09'))));
    }

    public function test_manual_time_and_corrections_record_the_right_timezone(): void
    {
        $this->setProjectTimezone('America/New_York');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

        $this->actingAs($this->member)->postJson(route('workspace.time.entries.store', $this->workspace), [
            'project' => $this->project->public_id,
            'date' => '2026-10-12',
            'hours' => '2',
            'idempotency_key' => $this->key(),
        ])->assertCreated();
        $manual = TimeEntry::query()->where('source', TimeEntrySource::Manual->value)->sole();
        $this->assertSame('America/New_York', $manual->timezone);

        $this->setProjectTimezone('');
        $this->actingAs($this->owner)
            ->from(route('workspace.time.index', $this->workspace))
            ->post(route('workspace.time.entries.adjust', [$this->workspace, $manual]), ['hours' => '-0.5', 'reason' => 'Lunch included by mistake', 'idempotency_key' => $this->key()])
            ->assertSessionHasNoErrors();

        $this->assertSame('America/New_York', TimeEntry::query()->where('adjusts_entry_id', $manual->id)->value('timezone'), 'a correction keeps the corrected entry\'s timezone');
    }

    public function test_invoice_hours_count_the_project_day(): void
    {
        $this->setProjectTimezone('America/New_York');
        $this->track('2026-10-09 22:00', '2026-10-09 23:30', 'America/New_York');

        $billable = fn (string $day) => app(TimeTotals::class)->seconds(new TimeQuery(
            $this->workspace->id,
            projectId: $this->project->id,
            from: CarbonImmutable::parse($day),
            to: CarbonImmutable::parse($day),
            billableOnly: true,
        ));

        $this->assertSame(5400, $billable('2026-10-09'));
        $this->assertSame(0, $billable('2026-10-10'));
    }

    public function test_pages_expose_the_working_timezones(): void
    {
        $this->setProjectTimezone('America/New_York');
        $this->at('2026-10-09 22:00', 'America/New_York');
        $this->startTimer();

        $this->actingAs($this->owner)->get(route('workspace.projects.show', [$this->workspace, $this->project->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.timezone', 'America/New_York')
                ->where('project.effective_timezone', 'America/New_York')
                ->where('project.workspace_timezone', 'Asia/Karachi'));

        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timezone', 'Asia/Karachi')
                ->where('timer.timezone', 'America/New_York'));

        $this->actingAs($this->owner)->get(route('dashboard', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('workspaceMeta.timezone', 'Asia/Karachi'));
    }
}
