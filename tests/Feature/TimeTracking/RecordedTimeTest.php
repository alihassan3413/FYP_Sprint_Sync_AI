<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeEntryData;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeEntryBatch;
use App\Modules\TimeTracking\Support\HoursInput;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\TestCase;

/**
 * Manual time, the owner's corrections and previous hours, and the totals
 * that combine them with tracked time (the October rollout).
 */
final class RecordedTimeTest extends TestCase
{
    use BuildsTimeTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->setUpTimeTeam();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addTime(array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->member)->postJson(route('workspace.time.entries.store', $this->workspace), [
            'project' => $this->project->public_id,
            'date' => '2026-10-12',
            'hours' => '2.5',
            'note' => 'Client follow-up',
            'idempotency_key' => $this->key(),
            ...$data,
        ]);
    }

    /** The real rollout: Oct 1–9 totals before the tracker. */
    private const ROLLOUT = [
        'Muhammad Usman Ghani' => '48.00',
        'Abdul Wahab' => '45.50',
        'Faheem' => '50.00',
        'Abdul Rehman' => '46.25',
        'Muhammad Faizan' => '49.00',
    ];

    /**
     * @param  array<string, string>  $hours
     * @param  array<string, mixed>  $data
     */
    private function previousHours(array $hours = self::ROLLOUT, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.time.previous-hours.create', $this->workspace))
            ->post(route('workspace.time.previous-hours.store', $this->workspace), [
                'project' => $this->project->public_id,
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-09',
                'reason' => 'Before SprintSync tracking',
                'rows' => collect($hours)->map(fn (string $value, string $name) => ['person' => $this->team[$name]->public_id, 'hours' => $value])->values()->all(),
                'idempotency_key' => $this->key(),
                ...$data,
            ]);
    }

    private function totals(): TimeTotals
    {
        return app(TimeTotals::class);
    }

    private function october(?int $personId = null, bool $billableOnly = false): int
    {
        return $this->totals()->seconds(new TimeQuery($this->workspace->id, personId: $personId, projectId: $this->project->id, from: CarbonImmutable::parse('2026-10-01'), to: CarbonImmutable::parse('2026-10-31'), billableOnly: $billableOnly));
    }

    /* ---- Hours input ---------------------------------------------------- */

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function hours(): array
    {
        return [
            '2.5' => ['2.5', 9000],
            '45.50' => ['45.50', 163800],
            '46.25' => ['46.25', 166500],
            '0.01 (36 seconds)' => ['0.01', 36],
            '8' => ['8', 28800],
            'three decimals' => ['2.555', null],
            'comma' => ['1,5', null],
            'text' => ['abc', null],
            'negative' => ['-1', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('hours')]
    public function test_hours_are_parsed_without_floats(string $input, ?int $seconds): void
    {
        $this->assertSame($seconds, HoursInput::toSeconds($input));
    }

    public function test_corrections_may_be_negative(): void
    {
        $this->assertSame(-1800, HoursInput::toSeconds('-0.5', allowNegative: true));
        $this->assertNull(HoursInput::toSeconds('-0.5'));
    }

    /* ---- Manual time ----------------------------------------------------- */

    public function test_a_person_adds_their_own_time_for_a_day(): void
    {
        $this->addTime(['task' => $this->task->public_id])->assertCreated()->assertJsonPath('entry.duration_seconds', 9000);

        $entry = TimeEntry::query()->sole();
        $this->assertSame(TimeEntrySource::Manual, $entry->source);
        $this->assertSame($this->usman()->id, $entry->person_id);
        $this->assertSame(9000, $entry->duration_seconds);
        $this->assertNull($entry->started_at, 'no invented start time');
        $this->assertSame('2026-10-12', $entry->period_start->toDateString());
        $this->assertSame($this->member->id, $entry->created_by);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_MANUAL_ADDED->value)->count());
    }

    public function test_a_double_submit_adds_time_once(): void
    {
        $key = $this->key();
        $this->addTime(['idempotency_key' => $key])->assertCreated();
        $this->addTime(['idempotency_key' => $key])->assertCreated();

        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_MANUAL_ADDED->value)->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidManual(): array
    {
        return [
            'too many decimals' => [['hours' => '2.555'], 'hours'],
            'zero' => [['hours' => '0'], 'error'],
            'more than a day' => [['hours' => '24.01'], 'error'],
            'future day' => [['date' => '2026-10-13'], 'error'],
            'bad date' => [['date' => '12/10/2026'], 'date'],
            'long note' => [['note' => str_repeat('a', 501)], 'note'],
            'missing key' => [['idempotency_key' => ''], 'idempotency_key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidManual')]
    public function test_manual_time_is_validated(array $data, string $field): void
    {
        $response = $this->addTime($data);

        $field === 'error' ? $response->assertUnprocessable()->assertJsonPath('error.code', 'TIME.INVALID') : $response->assertJsonValidationErrors($field);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_night_work_entered_today_is_allowed(): void
    {
        $this->workspace->update(['timezone' => 'Asia/Karachi']);
        $this->travelTo('2026-10-12 20:30:00');

        $this->addTime(['date' => '2026-10-13'])->assertCreated();
    }

    public function test_the_owner_adds_time_for_someone_on_the_team(): void
    {
        $this->addTime(['person' => $this->team['Faheem']->public_id], $this->owner)->assertCreated();

        $this->assertSame($this->team['Faheem']->id, TimeEntry::query()->sole()->person_id);
        $this->assertSame('Ali Hassan', TimeEntryData::fromModel(TimeEntry::query()->sole())->created_by);
    }

    public function test_a_member_cannot_add_time_for_someone_else(): void
    {
        $this->addTime(['person' => $this->team['Faheem']->public_id])->assertForbidden();
        $this->assertSame(0, TimeEntry::query()->count());
    }

    /* ---- Corrections and voids ------------------------------------------ */

    public function test_the_owner_corrects_tracked_time_without_changing_it(): void
    {
        $this->startTimer();
        $this->travel(5)->hours();
        $this->timer('stop');
        $tracked = TimeEntry::query()->sole();

        $adjust = fn (string $hours, string $key) => $this->actingAs($this->owner)
            ->withHeader('X-Inertia', 'true')
            ->post(route('workspace.time.entries.adjust', [$this->workspace, $tracked]), ['hours' => $hours, 'reason' => 'Forgot to stop the timer during lunch', 'idempotency_key' => $key]);

        $key = $this->key();
        $adjust('-0.5', $key)->assertSessionHasNoErrors();
        $adjust('-0.5', $key);
        $this->flushHeaders();

        $this->assertSame(18000, $tracked->fresh()->duration_seconds, 'the original tracked time is kept');
        $correction = TimeEntry::query()->where('source', 'adjustment')->sole();
        $this->assertSame(-1800, $correction->duration_seconds);
        $this->assertSame($tracked->id, $correction->adjusts_entry_id);
        $this->assertSame($this->owner->id, $correction->created_by);
        $this->assertSame(16200, $this->october($this->usman()->id), '5h − 0.5h');

        $this->withHeader('X-Inertia', 'true');
        $adjust('-4.6', $this->key())->assertSessionHas('error');
        $this->flushHeaders();
        $this->assertSame(16200, $this->october($this->usman()->id), 'never below zero');
    }

    public function test_tracked_entries_cannot_be_edited_or_deleted(): void
    {
        $this->startTimer();
        $this->travel(1)->hours();
        $this->timer('stop');
        $entry = TimeEntry::query()->sole();

        $this->expectException(LogicException::class);
        $entry->update(['duration_seconds' => 99]);
    }

    public function test_the_owner_voids_a_mistaken_manual_entry_once(): void
    {
        $this->addTime();
        $entry = TimeEntry::query()->sole();
        $void = fn () => $this->actingAs($this->owner)->post(route('workspace.time.entries.void', [$this->workspace, $entry]), ['reason' => 'Entered twice']);

        $void()->assertSessionHasNoErrors();
        $void();

        $this->assertNotNull($entry->fresh()->voided_at);
        $this->assertSame(0, $this->october());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_ENTRY_VOIDED->value)->count());
    }

    public function test_tracked_time_is_corrected_not_voided(): void
    {
        $this->startTimer();
        $this->travel(1)->hours();
        $this->timer('stop');

        $this->actingAs($this->owner)->withHeader('X-Inertia', 'true')
            ->post(route('workspace.time.entries.void', [$this->workspace, TimeEntry::query()->sole()]), ['reason' => 'x'])
            ->assertSessionHas('error');
        $this->flushHeaders();

        $this->assertNull(TimeEntry::query()->sole()->voided_at);
    }

    public function test_members_cannot_correct_or_void(): void
    {
        $this->addTime();
        $entry = TimeEntry::query()->sole();

        $this->actingAs($this->member)->post(route('workspace.time.entries.adjust', [$this->workspace, $entry]), ['hours' => '5', 'reason' => 'x', 'idempotency_key' => $this->key()])->assertForbidden();
        $this->actingAs($this->member)->post(route('workspace.time.entries.void', [$this->workspace, $entry]), ['reason' => 'x'])->assertForbidden();
        $this->assertSame(1, TimeEntry::query()->count());
    }

    /* ---- Previous hours (bulk) ------------------------------------------- */

    public function test_previous_hours_for_the_team_in_one_go(): void
    {
        $this->previousHours()->assertSessionHasNoErrors()->assertRedirect(route('workspace.time.index', $this->workspace));

        $batch = TimeEntryBatch::query()->sole();
        $this->assertSame('Before SprintSync tracking', $batch->note);
        $entries = TimeEntry::query()->where('batch_id', $batch->id)->get()->keyBy(fn (TimeEntry $entry) => $entry->person->name);

        $this->assertCount(5, $entries);
        $this->assertSame(172800, $entries['Muhammad Usman Ghani']->duration_seconds);
        $this->assertSame(163800, $entries['Abdul Wahab']->duration_seconds);
        $this->assertSame(166500, $entries['Abdul Rehman']->duration_seconds);
        foreach ($entries as $entry) {
            $this->assertSame(TimeEntrySource::Adjustment, $entry->source);
            $this->assertSame('2026-10-01', $entry->period_start->toDateString());
            $this->assertSame('2026-10-09', $entry->period_end->toDateString());
            $this->assertNull($entry->started_at, 'a total for the period, not invented shifts');
        }

        $audit = AuditLog::query()->where('action', AuditAction::TIME_HISTORICAL_ADDED->value)->sole();
        $this->assertSame(5, $audit->metadata['people']);
        $this->assertSame(859500, $audit->metadata['total_seconds']);
    }

    public function test_saving_previous_hours_twice_saves_them_once(): void
    {
        $key = $this->key();
        $this->previousHours(data: ['idempotency_key' => $key]);
        $this->previousHours(data: ['idempotency_key' => $key])->assertSessionHasNoErrors();

        $this->assertSame(1, TimeEntryBatch::query()->count());
        $this->assertSame(5, TimeEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_HISTORICAL_ADDED->value)->count());
    }

    public function test_one_bad_row_saves_nothing(): void
    {
        $this->previousHours([...self::ROLLOUT, 'Faheem' => '50.005'])->assertSessionHasErrors('rows.2.hours');
        $this->withHeader('X-Inertia', 'true');
        $this->previousHours([...self::ROLLOUT, 'Faheem' => '300'])->assertSessionHas('error');
        $this->flushHeaders();

        $this->assertSame(0, TimeEntry::query()->count());
        $this->assertSame(0, TimeEntryBatch::query()->count());
    }

    public function test_blank_rows_are_skipped(): void
    {
        $this->previousHours(['Muhammad Usman Ghani' => '48', 'Faheem' => ''])->assertSessionHasNoErrors();

        $this->assertSame(1, TimeEntry::query()->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'ends before it starts' => [['period_start' => '2026-10-09', 'period_end' => '2026-10-01']],
            'in the future' => [['period_end' => '2026-10-20']],
            'across two months' => [['period_start' => '2026-09-28', 'period_end' => '2026-10-03']],
            'no reason' => [['reason' => '']],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidPeriods')]
    public function test_previous_hours_periods_are_validated(array $data): void
    {
        $this->withHeader('X-Inertia', 'true');
        $response = $this->previousHours(data: $data);
        $this->flushHeaders();

        $this->assertTrue($response->getSession()->has('errors') || $response->getSession()->has('error'));
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_only_the_owner_adds_previous_hours(): void
    {
        $this->previousHours(as: $this->member)->assertForbidden();
        $this->actingAs($this->member)->get(route('workspace.time.previous-hours.create', $this->workspace))->assertForbidden();

        $this->assertSame(0, TimeEntry::query()->count());
    }

    /* ---- Totals: the October rollout ------------------------------------- */

    public function test_october_combines_previous_hours_tracked_and_manual_time(): void
    {
        $this->previousHours();

        $this->travelTo('2026-10-10 09:00:00');
        $this->startTimer();
        $this->travelTo('2026-10-10 17:00:00');
        $this->timer('stop');

        $this->travelTo('2026-10-11 12:00:00');
        $this->addTime(['date' => '2026-10-11', 'hours' => '1.25']);
        $this->addTime(['date' => '2026-10-11', 'hours' => '2', 'billable' => false, 'note' => 'Internal training']);

        $usman = $this->usman()->id;
        $this->assertSame(172800 + 28800 + 4500 + 7200, $this->october($usman), '48h + 8h tracked + 1.25h + 2h');
        $this->assertSame(172800 + 28800 + 4500, $this->october($usman, billableOnly: true), 'non-billable training left out');
        $this->assertSame(166500, $this->october($this->team['Abdul Rehman']->id));

        $byPerson = collect($this->totals()->grouped(new TimeQuery($this->workspace->id, projectId: $this->project->id, from: CarbonImmutable::parse('2026-10-01'), to: CarbonImmutable::parse('2026-10-31')), ['person_id']))
            ->pluck('seconds', 'person_id');
        $this->assertSame(213300, $byPerson[$usman]);
        $this->assertSame(163800, $byPerson[$this->team['Abdul Wahab']->id]);
        $this->assertIsInt($byPerson[$usman]);

        $byDay = collect($this->totals()->grouped(new TimeQuery($this->workspace->id, personId: $usman), ['period_start']))->pluck('seconds', 'period_start');
        $this->assertSame(['2026-10-01' => 172800, '2026-10-10' => 28800, '2026-10-11' => 11700], $byDay->all());

        $this->assertSame(0, $this->totals()->seconds(new TimeQuery($this->workspace->id, personId: $usman, from: CarbonImmutable::parse('2026-09-01'), to: CarbonImmutable::parse('2026-09-30'))));
    }

    public function test_totals_group_by_task_and_ignore_other_workspaces(): void
    {
        $this->addTime(['task' => $this->task->public_id, 'hours' => '3']);
        $this->addTime(['hours' => '1']);

        $byTask = collect($this->totals()->grouped(new TimeQuery($this->workspace->id), ['task_id']))->pluck('seconds', 'task_id');
        $this->assertSame(10800, $byTask[$this->task->id]);
        $this->assertSame(3600, $byTask['']);

        $this->assertSame(0, $this->totals()->seconds(new TimeQuery($this->workspace->id + 999)));
    }
}
