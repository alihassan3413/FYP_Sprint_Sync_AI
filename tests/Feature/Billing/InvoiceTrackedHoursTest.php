<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Actions\IssueInvoiceAction;
use App\Modules\Billing\Data\AutomationReason;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\InvoiceAutomationSafety;
use App\Modules\Billing\Support\TrackedHours;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\TimeTracking\Actions\AddHistoricalHoursAction;
use App\Modules\TimeTracking\Actions\AddManualTimeAction;
use App\Modules\TimeTracking\Actions\EndLunchAction;
use App\Modules\TimeTracking\Actions\ManageTimeOffAction;
use App\Modules\TimeTracking\Actions\RecordActivitySignalAction;
use App\Modules\TimeTracking\Actions\ResolveTimeReviewAction;
use App\Modules\TimeTracking\Actions\SaveTimePolicyAction;
use App\Modules\TimeTracking\Actions\StartLunchAction;
use App\Modules\TimeTracking\Actions\StartTimerAction;
use App\Modules\TimeTracking\Actions\StopTimerAction;
use App\Modules\TimeTracking\Data\LeaveType;
use App\Modules\TimeTracking\Data\OvertimeMode;
use App\Modules\TimeTracking\Models\TimeReview;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\TestCase;

/**
 * Hourly invoices take their recorded hours from time tracking; the owner
 * decides the billed hours. The two never overwrite each other.
 */
final class InvoiceTrackedHoursTest extends TestCase
{
    use BuildsRocketFlood, RefreshDatabase;

    private Project $csrProject;

    private BillingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->setUpRocketFlood();
        $this->csrProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'RocketFlood CSR']);
        $this->plan = $this->csrTeam();
        $this->plan->projects()->sync([$this->csrProject->id]);
    }

    private function person(string $name): Person
    {
        return $this->team[$name];
    }

    /** A historical total in October (owner-added, billable unless said otherwise). */
    private function hours(string $name, int $seconds, bool $billable = true, ?Project $project = null, string $from = '2026-10-01', string $to = '2026-10-14'): void
    {
        app(AddHistoricalHoursAction::class)->handle($this->workspace, $project ?? $this->csrProject, null, CarbonImmutable::parse($from), CarbonImmutable::parse($to), 'Before tracking', $billable, [['person' => $this->person($name), 'seconds' => $seconds]], (string) Str::ulid(), $this->owner);
    }

    private function october(): Invoice
    {
        $this->travelTo('2026-11-01 09:00:00');

        return app(GenerateInvoiceFromPlanAction::class)->handle($this->plan, app(BillingSchedule::class)->latestDueCycle($this->plan, CarbonImmutable::parse('2026-11-01')), null)->fresh('lines');
    }

    private function line(Invoice $invoice, string $name): InvoiceLine
    {
        return $invoice->fresh('lines')->lines->firstWhere('description', $name);
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $reasons
     */
    private function bill(Invoice $invoice, array $lines, array $reasons = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->put(route('workspace.invoices.lines.update', [$this->workspace, $invoice]), ['lines' => $lines, 'reasons' => $reasons]);
    }

    /* ---- Conversion -------------------------------------------------------- */

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function conversions(): array
    {
        return [
            '0s' => [0, 0],
            '17s rounds down' => [17, 0],
            '18s rounds up (half)' => [18, 1],
            '53s' => [53, 1],
            '54s' => [54, 2],
            '1h' => [3600, 100],
            '160h 15m' => [576900, 16025],
        ];
    }

    #[DataProvider('conversions')]
    public function test_seconds_become_hundredths_of_an_hour_deterministically(int $seconds, int $centi): void
    {
        $this->assertSame($centi, TrackedHours::centi($seconds));
    }

    /* ---- Pre-filling --------------------------------------------------------- */

    public function test_billable_tracked_time_prefills_the_hourly_draft(): void
    {
        $this->hours('Muhammad Usman Ghani', 576900);
        $this->hours('Abdul Wahab', 360000);

        $invoice = $this->october();

        $usman = $this->line($invoice, 'Muhammad Usman Ghani');
        $this->assertSame(576900, $usman->tracked_seconds);
        $this->assertSame(16025, $usman->tracked_quantity_centi);
        $this->assertSame(16025, $usman->quantity_centi, 'billed starts as recorded');
        $this->assertSame(88939, $usman->amount_minor, '160.25h × $5.55');
        $this->assertSame(10000, $this->line($invoice, 'Abdul Wahab')->quantity_centi);

        $faheem = $this->line($invoice, 'Faheem');
        $this->assertSame(0, $faheem->tracked_seconds);
        $this->assertNull($faheem->quantity_centi, 'no recorded time is not taken as "bill nothing"');
        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->status);
        $this->assertSame([$this->csrProject->id], $invoice->time_project_ids);
    }

    public function test_a_draft_with_everyone_recorded_is_ready_to_review(): void
    {
        foreach (['Muhammad Usman Ghani', 'Abdul Wahab', 'Faheem', 'Abdul Rehman', 'Muhammad Faizan'] as $name) {
            $this->hours($name, 3600 * 100);
        }

        $this->assertSame(InvoiceStatus::ReadyToReview, $this->october()->status);
    }

    public function test_only_billable_work_on_the_plans_projects_counts(): void
    {
        $this->hours('Muhammad Usman Ghani', 3600 * 100);
        $this->hours('Muhammad Usman Ghani', 3600 * 10, billable: false);
        $this->hours('Muhammad Usman Ghani', 3600 * 7, project: Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Other client']));

        app(SaveTimePolicyAction::class)->handle($this->workspace, ['idle_enabled' => false, 'idle_seconds' => null, 'weekly_target_seconds' => null, 'lunch_enabled' => true, 'lunch_allowance_seconds' => 3600, 'overtime_mode' => OvertimeMode::TrackOnly, 'integrity_enabled' => false], $this->owner);
        app(ManageTimeOffAction::class)->addHoliday($this->workspace, CarbonImmutable::parse('2026-10-08'), 'Holiday', 28800, [], $this->owner);
        app(ManageTimeOffAction::class)->addLeave($this->person('Muhammad Usman Ghani'), CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-13'), LeaveType::Paid, 28800, null, (string) Str::ulid(), $this->owner);
        $this->usmanLunch();

        $usman = $this->line($this->october(), 'Muhammad Usman Ghani');
        $this->assertSame(360000 + 10800 + 3600, $usman->tracked_seconds, '100h + 3h tracked + 1h paid lunch; not non-billable, other projects, holiday or leave');
        $this->assertSame(10400, $usman->quantity_centi);
    }

    private function usmanLunch(): void
    {
        $usman = $this->person('Muhammad Usman Ghani');
        $this->travelTo('2026-10-15 11:00:00');
        app(StartTimerAction::class)->handle($usman, $this->csrProject, null, true, null, (string) Str::ulid(), $this->owner);
        $this->travelTo('2026-10-15 14:00:00');
        app(StartLunchAction::class)->handle($usman, (string) Str::ulid());
        $this->travelTo('2026-10-15 15:00:00');
        app(StopTimerAction::class)->handle($usman, (string) Str::ulid(), $this->owner);
        /* 3h of billable work and 1h of paid lunch (billable, within the allowance). */
    }

    public function test_recorded_hours_include_allowed_paid_lunch_and_only_counted_excess(): void
    {
        app(SaveTimePolicyAction::class)->handle($this->workspace, ['idle_enabled' => false, 'idle_seconds' => null, 'weekly_target_seconds' => null, 'lunch_enabled' => true, 'lunch_allowance_seconds' => 3600, 'overtime_mode' => OvertimeMode::TrackOnly, 'integrity_enabled' => false], $this->owner);
        $usman = $this->person('Muhammad Usman Ghani');
        $this->hours('Muhammad Usman Ghani', 152 * 3600 - 2 * 25200, from: '2026-10-01', to: '2026-10-13');

        /* Two days: 7h work + lunch each; the first lunch runs 74 minutes. */
        foreach (['2026-10-15', '2026-10-16'] as $index => $day) {
            $this->travelTo("{$day} 09:00:00");
            app(StartTimerAction::class)->handle($usman, $this->csrProject, null, true, null, (string) Str::ulid(), $this->owner);
            $this->travelTo("{$day} 12:00:00");
            app(StartLunchAction::class)->handle($usman, (string) Str::ulid());
            $this->travelTo("{$day} ".($index === 0 ? '13:14:00' : '13:00:00'));
            app(EndLunchAction::class)->handle($usman, (string) Str::ulid());
            $this->travelTo("{$day} ".($index === 0 ? '17:14:00' : '17:00:00'));
            app(StopTimerAction::class)->handle($usman, (string) Str::ulid(), $this->owner);
        }

        $invoice = $this->october();
        $line = $this->line($invoice, 'Muhammad Usman Ghani');
        $this->assertSame(152 * 3600 + 2 * 3600, $line->tracked_seconds, '152h work + 2 × 60m lunch; the extra 14m waits for the owner');
        $this->assertSame(15400, $line->tracked_quantity_centi);

        $excess = TimeReview::query()->where('kind', 'lunch_excess')->sole();
        app(ResolveTimeReviewAction::class)->handle($excess, 'counted', 'Agreed', $this->owner);
        $this->actingAs($this->owner)->post(route('workspace.invoices.refresh-hours', [$this->workspace, $invoice]));
        $this->assertSame(152 * 3600 + 2 * 3600 + 840, $this->line($invoice, 'Muhammad Usman Ghani')->tracked_seconds, 'counted excess is billable');

        /* The owner can still bill different hours, with a reason. */
        $this->bill($invoice, ['0' => '150'], ['0' => 'Client agreement'])->assertSessionHasNoErrors();
        $this->assertSame(15000, $this->line($invoice, 'Muhammad Usman Ghani')->quantity_centi);
        $this->assertSame(15423, $this->line($invoice, 'Muhammad Usman Ghani')->tracked_quantity_centi);
    }

    /* ---- Billed hours and overrides --------------------------------------------- */

    public function test_the_owner_can_bill_different_hours_with_a_reason(): void
    {
        $this->hours('Muhammad Usman Ghani', 576900);
        $invoice = $this->october();

        $this->bill($invoice, ['0' => '158'])->assertSessionHasErrors('reasons.0');
        $this->assertSame(16025, $this->line($invoice, 'Muhammad Usman Ghani')->quantity_centi);

        $this->bill($invoice, ['0' => '158'], ['0' => 'Internal training'])->assertSessionHasNoErrors();

        $usman = $this->line($invoice, 'Muhammad Usman Ghani');
        $this->assertSame(15800, $usman->quantity_centi, 'billed');
        $this->assertSame(16025, $usman->tracked_quantity_centi, 'recorded is untouched');
        $this->assertSame(576900, $usman->tracked_seconds);
        $this->assertSame('Internal training', $usman->quantity_reason);
        $this->assertSame($this->owner->id, $usman->quantity_set_by);
        $this->assertSame(87690, $usman->amount_minor, 'the money uses billed hours: 158 × $5.55');

        $audit = AuditLog::query()->where('action', AuditAction::INVOICE_HOURS_OVERRIDDEN->value)->sole();
        $this->assertSame(-225, $audit->metadata['difference_centi']);
        $this->assertSame('Internal training', $audit->metadata['reason']);
    }

    public function test_billing_the_recorded_hours_again_clears_the_reason(): void
    {
        $this->hours('Muhammad Usman Ghani', 576900);
        $invoice = $this->october();
        $this->bill($invoice, ['0' => '158'], ['0' => 'Client agreement']);

        $this->bill($invoice, ['0' => '160.25'])->assertSessionHasNoErrors();

        $this->assertNull($this->line($invoice, 'Muhammad Usman Ghani')->quantity_reason);
    }

    public function test_hours_without_tracked_data_can_be_entered_by_hand(): void
    {
        $this->hours('Muhammad Usman Ghani', 576900);
        $invoice = $this->october();

        /* Faheem tracked nothing: hours come from elsewhere and need a reason. */
        $this->bill($invoice, ['2' => '160'])->assertSessionHasErrors('reasons.2');
        $this->bill($invoice, ['2' => '160'], ['2' => 'Hours provided externally'])->assertSessionHasNoErrors();

        $faheem = $this->line($invoice, 'Faheem');
        $this->assertSame(16000, $faheem->quantity_centi);
        $this->assertSame(0, $faheem->tracked_seconds);
    }

    public function test_plans_without_time_tracking_keep_working_as_before(): void
    {
        $this->plan->projects()->sync([]);
        $invoice = $this->october();

        $this->assertNull($invoice->time_project_ids);
        $this->assertNull($this->line($invoice, 'Faheem')->tracked_seconds);
        $this->bill($invoice, ['2' => '160'])->assertSessionHasNoErrors();
        $this->assertSame(16000, $this->line($invoice, 'Faheem')->quantity_centi);
    }

    /* ---- Late time and refreshing -------------------------------------------- */

    public function test_new_time_before_approval_is_offered_not_applied_silently(): void
    {
        $this->hours('Muhammad Usman Ghani', 576900);
        $this->hours('Abdul Wahab', 360000);
        $invoice = $this->october();
        $this->bill($invoice, ['1' => '99'], ['1' => 'Courtesy reduction']);

        /* Nov 2: Oct 31 time is added late for both. */
        $this->travelTo('2026-11-02 10:00:00');
        foreach (['Muhammad Usman Ghani', 'Abdul Wahab'] as $name) {
            app(AddManualTimeAction::class)->handle($this->person($name), $this->csrProject, null, CarbonImmutable::parse('2026-10-31'), 9000, true, 'Late entry', (string) Str::ulid(), $this->owner);
        }

        $this->assertSame(16025, $this->line($invoice, 'Muhammad Usman Ghani')->quantity_centi, 'nothing changes behind the owner\'s back');
        $this->actingAs($this->owner)->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page->where('trackedUpdate.delta_seconds', 18000)->where('trackedUpdate.lines', 2));

        $this->actingAs($this->owner)->post(route('workspace.invoices.refresh-hours', [$this->workspace, $invoice]))->assertSessionHasNoErrors();

        $usman = $this->line($invoice, 'Muhammad Usman Ghani');
        $this->assertSame(16275, $usman->tracked_quantity_centi);
        $this->assertSame(16275, $usman->quantity_centi, 'billed followed: the owner had not changed it');

        $wahab = $this->line($invoice, 'Abdul Wahab');
        $this->assertSame(10250, $wahab->tracked_quantity_centi);
        $this->assertSame(9900, $wahab->quantity_centi, 'the owner\'s own hours stay');
        $this->assertSame('Courtesy reduction', $wahab->quantity_reason);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_HOURS_REFRESHED->value)->count());
        $this->actingAs($this->owner)->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page->where('trackedUpdate', null));
    }

    public function test_an_approved_invoice_never_changes(): void
    {
        foreach (['Muhammad Usman Ghani', 'Abdul Wahab', 'Faheem', 'Abdul Rehman', 'Muhammad Faizan'] as $name) {
            $this->hours($name, 3600 * 100);
        }
        $invoice = $this->october();
        $invoice = app(ApproveInvoiceAction::class)->handle($invoice, $invoice->version, $this->owner);

        app(AddManualTimeAction::class)->handle($this->person('Muhammad Usman Ghani'), $this->csrProject, null, CarbonImmutable::parse('2026-10-31'), 9000, true, 'Late', (string) Str::ulid(), $this->owner);

        $this->actingAs($this->owner)->withHeader('X-Inertia', 'true')->post(route('workspace.invoices.refresh-hours', [$this->workspace, $invoice]))->assertSessionHas('error');
        $this->flushHeaders();
        $this->assertSame(10000, $this->line($invoice, 'Muhammad Usman Ghani')->quantity_centi);
        $this->assertSame(360000, $this->line($invoice, 'Muhammad Usman Ghani')->tracked_seconds);

        $issued = app(IssueInvoiceAction::class)->handle($invoice->fresh(), $this->owner, sendNow: true);
        $this->expectException(\LogicException::class);
        $issued->lines->first()->forceFill(['tracked_seconds' => 1])->save();
    }

    /* ---- Safety engine ------------------------------------------------------------- */

    public function test_unresolved_activity_reviews_hold_automatic_sending(): void
    {
        $this->hours('Muhammad Usman Ghani', 3600 * 100);
        app(SaveTimePolicyAction::class)->handle($this->workspace, ['idle_enabled' => false, 'idle_seconds' => null, 'weekly_target_seconds' => null, 'lunch_enabled' => false, 'lunch_allowance_seconds' => null, 'overtime_mode' => OvertimeMode::TrackOnly, 'integrity_enabled' => true], $this->owner);
        $this->travelTo('2026-10-15 10:30:00');
        app(RecordActivitySignalAction::class)->handle($this->person('Muhammad Usman Ghani'), CarbonImmutable::parse('2026-10-15 10:05:00'), CarbonImmutable::parse('2026-10-15 10:25:00'), 40, 30000, 50);
        $invoice = $this->october();

        $result = app(InvoiceAutomationSafety::class)->evaluate($invoice, CarbonImmutable::parse('2026-11-03'));

        $this->assertTrue($result->has(AutomationReason::TimeActivityReview));
        $this->assertSame('Time activity needs review before this invoice can be sent automatically.', collect($result->reasons)->firstWhere('code', 'time_activity_review')['message']);
    }

    public function test_the_hours_check_compares_billed_hours(): void
    {
        foreach (['Muhammad Usman Ghani', 'Abdul Wahab', 'Faheem', 'Abdul Rehman', 'Muhammad Faizan'] as $name) {
            $this->hours($name, 3600 * 100, from: '2026-09-01', to: '2026-09-14');
            $this->hours($name, 3600 * 100);
        }
        $this->travelTo('2026-10-01 09:00:00');
        $september = app(GenerateInvoiceFromPlanAction::class)->handle($this->plan, app(BillingSchedule::class)->latestDueCycle($this->plan, CarbonImmutable::parse('2026-10-01')), null);
        app(IssueInvoiceAction::class)->handle(app(ApproveInvoiceAction::class)->handle($september, $september->fresh()->version, $this->owner), $this->owner, sendNow: true);

        $invoice = $this->october();
        $safety = app(InvoiceAutomationSafety::class);
        $this->assertFalse($safety->evaluate($invoice, CarbonImmutable::parse('2026-11-03'))->has(AutomationReason::HoursVariance));

        $this->bill($invoice, ['0' => '140'], ['0' => 'Extra coverage agreed'])->assertSessionHasNoErrors();
        $this->assertTrue($safety->evaluate($invoice->fresh(), CarbonImmutable::parse('2026-11-03'))->has(AutomationReason::HoursVariance), 'billed 140h vs 100h last month');
    }

    /* ---- Access ---------------------------------------------------------------------- */

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_refreshes_or_overrides_hours(UserRole $role): void
    {
        $this->hours('Muhammad Usman Ghani', 576900);
        $invoice = $this->october();
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->actingAs($user)->post(route('workspace.invoices.refresh-hours', [$this->workspace, $invoice]))->assertForbidden();
        $this->bill($invoice, ['0' => '1'], ['0' => 'x'], $user)->assertForbidden();
        $this->assertSame(16025, $this->line($invoice, 'Muhammad Usman Ghani')->quantity_centi);
    }

    public function test_the_invoice_page_shows_recorded_and_billed_hours(): void
    {
        $this->hours('Muhammad Usman Ghani', 576900);
        $invoice = $this->october();

        $this->actingAs($this->owner)->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.uses_time_tracking', true)
                ->where('invoice.lines.0.tracked_quantity_centi', 16025)
                ->where('invoice.lines.0.quantity_centi', 16025)
                ->where('trackedUpdate', null));
    }
}
