<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\ActorType;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Data\AuditLogEntryData;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\GenerateDueRecurringInvoiceAction;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Actions\IssueInvoiceAction;
use App\Modules\Billing\Actions\ProcessAutoSendInvoiceAction;
use App\Modules\Billing\Actions\SaveBillingPlanAction;
use App\Modules\Billing\Actions\SetBillingPlanPausedAction;
use App\Modules\Billing\Actions\UpdateInvoiceLinesAction;
use App\Modules\Billing\Data\AutomationReason;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Data\StoreBillingPlanData;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\InvoiceAutomationSafety;
use App\Modules\Billing\Support\OwnerReminders;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\Feature\Billing\Concerns\FakesWorkspaceMail;
use Tests\TestCase;

/**
 * Phase 8: recurring generation, automatic sending with safety checks, and
 * owner reminders. Plans are created on 2026-10-03 (their automation
 * boundary), so October's invoices (generated Nov 1) are the first ones
 * automation handles; September's are made by hand as history.
 */
final class RecurringAutomationTest extends TestCase
{
    use BuildsRocketFlood, FakesWorkspaceMail, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->setUpRocketFlood();
        $this->fakeWorkspaceMail();
    }

    /* ---- Helpers ------------------------------------------------------- */

    /** Runs the three scheduled automation steps as if it were $moment in $timezone. */
    private function runAt(string $moment, string $timezone = 'UTC'): void
    {
        $this->travelTo(CarbonImmutable::parse($moment, $timezone)->utc());
        $this->artisan('billing:generate-recurring-invoices')->run();
        $this->artisan('billing:process-recurring-invoices')->run();
        $this->artisan('billing:send-owner-reminders')->run();
    }

    private function autoSend(BillingPlan $plan): BillingPlan
    {
        $plan->update(['delivery_mode' => DeliveryMode::AutoSend, 'reminder_days_before' => null]);

        return $plan->fresh();
    }

    /**
     * September's invoice, prepared, approved and issued by hand (history).
     *
     * @param  array<int, int>  $hours
     */
    private function issuedBaseline(BillingPlan $plan, array $hours = []): Invoice
    {
        $invoice = app(GenerateInvoiceFromPlanAction::class)->handle(
            $plan,
            app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse('2026-10-03')),
            $this->owner,
        );

        if ($hours !== []) {
            $invoice = app(UpdateInvoiceLinesAction::class)->handle($invoice, $hours, $this->owner);
        }

        $invoice = app(ApproveInvoiceAction::class)->handle($invoice->fresh(), $invoice->fresh()->version, $this->owner);

        return app(IssueInvoiceAction::class)->handle($invoice, $this->owner, sendNow: true);
    }

    private function october(BillingPlan $plan): ?Invoice
    {
        return Invoice::query()->where('billing_plan_id', $plan->id)->whereDate('period_start', '2026-10-01')->first();
    }

    /** October's invoice generated directly (no automation run). */
    private function octoberDraft(BillingPlan $plan): Invoice
    {
        return app(GenerateInvoiceFromPlanAction::class)->handle(
            $plan,
            app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse('2026-11-05')),
            null,
        );
    }

    private function notifications(?string $type = null): int
    {
        return $this->owner->notifications()->when($type, fn ($query) => $query->where('data->type', $type))->count();
    }

    /** @return list<string> */
    private function messages(): array
    {
        return $this->owner->notifications()->reorder()->oldest()->get()->pluck('data.message')->all();
    }

    /**
     * @param  array<int, int>  $hours
     */
    private function enterHours(Invoice $invoice, array $hours): Invoice
    {
        return app(UpdateInvoiceLinesAction::class)->handle($invoice->fresh(), $hours, $this->owner);
    }

    /** @return list<string> */
    private function holdCodes(Invoice $invoice): array
    {
        return array_column($invoice->fresh()->automation_hold ?? [], 'code');
    }

    /** 160 hours each, in hundredths of an hour as the hours editor sends them. */
    private const FULL_MONTH = [0 => 16000, 1 => 16000, 2 => 16000, 3 => 16000, 4 => 16000];

    /* ---- Generation ---------------------------------------------------- */

    public function test_a_fixed_invoice_is_generated_on_its_local_generation_day(): void
    {
        $plan = $this->devAndOffice();

        $this->runAt('2026-10-31 23:59');
        $this->assertNull($this->october($plan));

        $this->runAt('2026-11-01 00:05');
        $invoice = $this->october($plan);

        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertSame('2026-11-01', $invoice->generated_on->toDateString());
        $this->assertSame(505920, $invoice->total_minor);

        $entry = AuditLog::query()->where('action', AuditAction::INVOICE_GENERATED->value)->where('subject_id', $invoice->id)->sole();
        $this->assertSame(ActorType::System, $entry->actor_type);
        $this->assertNull($entry->user_id);
        $this->assertSame('SprintSync', AuditLogEntryData::fromModel($entry)->actor_name);
    }

    public function test_an_hourly_invoice_is_generated_on_the_1st_and_needs_hours(): void
    {
        $plan = $this->csrTeam();

        $this->runAt('2026-11-01 00:05');
        $invoice = $this->october($plan);

        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->status);
        $this->assertSame('2026-11-03', $invoice->planned_send_on->toDateString(), 'created on the 1st, planned for the 3rd');
        $this->assertSame(['CSR Team · October hours are ready to complete.'], $this->messages());
    }

    public function test_running_generation_again_creates_nothing_more(): void
    {
        $plan = $this->csrTeam();

        $this->runAt('2026-11-01 00:05');
        $this->runAt('2026-11-01 00:05');
        $this->runAt('2026-11-01 05:00');
        $this->runAt('2026-11-02 12:00');

        $this->assertSame(1, Invoice::query()->where('billing_plan_id', $plan->id)->whereDate('period_start', '2026-10-01')->count());
        $this->assertSame(1, $this->notifications());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_GENERATED->value)->count());
    }

    public function test_concurrent_generation_returns_the_same_invoice(): void
    {
        $plan = $this->devAndOffice();
        $this->travelTo('2026-11-01 01:00');
        $now = CarbonImmutable::now();

        $first = app(GenerateDueRecurringInvoiceAction::class)->handle(BillingPlan::query()->findOrFail($plan->id), $now);
        $second = app(GenerateDueRecurringInvoiceAction::class)->handle(BillingPlan::query()->findOrFail($plan->id), $now);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_a_paused_plan_is_not_generated_and_resuming_does_not_backfill(): void
    {
        $plan = $this->devAndOffice();
        app(SetBillingPlanPausedAction::class)->handle($plan, true, $this->owner);

        $this->runAt('2026-11-01 00:05');
        $this->runAt('2026-12-01 00:05');
        $this->assertSame(0, Invoice::query()->count());

        $this->travelTo('2026-12-04 10:00');
        app(SetBillingPlanPausedAction::class)->handle($plan->fresh(), false, $this->owner);
        $this->runAt('2026-12-05 10:00');

        $this->assertSame(0, Invoice::query()->count(), 'neither October nor November is backfilled');

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plans.0.due_missed_while_paused', true)
                ->where('plans.0.due_period_start', '2026-11-01'));

        $this->runAt('2027-01-01 00:05');
        $this->assertSame(1, Invoice::query()->count(), 'December is generated normally after resuming');
    }

    public function test_activation_never_generates_history(): void
    {
        $plan = $this->devAndOffice();

        $this->runAt('2026-10-03 10:05');
        $this->runAt('2026-10-15 10:05');
        $this->assertSame(0, Invoice::query()->count(), 'September (generated Oct 1) predates the boundary');

        $plan->forceFill(['automation_enabled_at' => null])->save();
        $this->runAt('2026-11-01 00:05');
        $this->assertSame(0, Invoice::query()->count(), 'a plan without a boundary is never automated');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function timezones(): array
    {
        return [
            'UTC' => ['UTC', '2026-10-31 23:30:00', '2026-11-01 00:30:00'],
            'Lahore (UTC+5)' => ['Asia/Karachi', '2026-10-31 18:30:00', '2026-10-31 19:30:00'],
            'New York (DST ends Nov 1)' => ['America/New_York', '2026-11-01 03:30:00', '2026-11-01 04:30:00'],
        ];
    }

    #[DataProvider('timezones')]
    public function test_generation_follows_the_workspace_calendar(string $timezone, string $beforeUtc, string $afterUtc): void
    {
        $this->workspace->update(['timezone' => $timezone]);
        $plan = $this->devAndOffice();

        $this->runAt($beforeUtc);
        $this->assertNull($this->october($plan), "still October 31 in {$timezone}");

        $this->runAt($afterUtc);
        $this->assertNotNull($this->october($plan), "November 1 in {$timezone}");
        $this->assertSame('2026-11-01', $this->october($plan)->generated_on->toDateString());
    }

    /* ---- First invoice ------------------------------------------------- */

    public function test_the_first_auto_send_invoice_always_waits_for_review(): void
    {
        $this->connectMail();
        $plan = $this->autoSend($this->devAndOffice());

        $this->runAt('2026-11-01 00:05');
        $this->runAt('2026-11-01 09:30');

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertFalse($invoice->approved_automatically);
        $this->assertSame([AutomationReason::FirstInvoice->value], $this->holdCodes($invoice));
        $this->assertSame(['Dev & Office for October needs your review. First invoice — review it before automatic sending starts.'], $this->messages());

        $held = AuditLog::query()->where('action', AuditAction::INVOICE_AUTOMATION_HELD->value)->sole();
        $this->assertSame(ActorType::System, $held->actor_type);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('needsYou.0.attention.kind', 'safety_review')
                ->where('needsYou.0.attention.label', 'Needs review')
                ->where('needsYou.0.attention.reason', 'First invoice — review it before automatic sending starts.'));
    }

    public function test_a_safe_invoice_after_the_first_is_approved_automatically_then_sent(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);

        $this->runAt('2026-11-01 08:30');
        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status, 'waits until 9:00 so the owner is awake for the cancel window');

        $this->runAt('2026-11-01 09:00');
        $this->runAt('2026-11-01 09:15');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Approved, $invoice->status);
        $this->assertTrue($invoice->approved_automatically);
        $this->assertNull($invoice->approved_by, 'no person is borrowed as the approver');
        $this->assertSame('2026-11-01 10:00:00', $invoice->send_after->toDateTimeString(), 'the usual 1-hour cancel window');
        $this->assertNull($invoice->number);

        $approvals = AuditLog::query()->where('action', AuditAction::INVOICE_APPROVED->value)->where('subject_id', $invoice->id)->get();
        $this->assertCount(1, $approvals);
        $this->assertSame(ActorType::System, $approvals[0]->actor_type);
        $this->assertStringStartsWith('SprintSync automatically approved "Dev & Office" for RocketFlood.', $approvals[0]->description);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page->where('invoice.approved_automatically', true)->where('invoice.approved_by', 'SprintSync'));

        $this->travelTo('2026-11-01 10:00');
        $this->artisan('billing:dispatch-approved-invoices')->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-2026-0002', $invoice->number);
        $this->assertSame(DeliveryStatus::Sent, $invoice->delivery()->sole()->status);
        $this->assertCount(1, $this->smtp->sent);
    }

    public function test_the_qa_simulator_stores_times_in_utc_for_any_timezone(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);

        $this->artisan('billing:simulate', ['at' => '2026-11-01 14:30', '--timezone' => 'Asia/Karachi'])->assertSuccessful();

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::Approved, $invoice->status);
        $this->assertSame('2026-11-01 09:30:00', $invoice->approved_at->toDateTimeString(), '14:30 in Lahore is 09:30 UTC');
    }

    public function test_the_owner_can_cancel_an_automatic_approval_and_it_is_not_approved_again(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);
        $this->runAt('2026-11-01 09:05');
        $invoice = $this->october($plan);

        $this->actingAs($this->owner)
            ->post(route('workspace.invoices.cancel-sending', [$this->workspace, $invoice]))
            ->assertSessionHasNoErrors();

        $this->runAt('2026-11-01 09:30');
        $this->runAt('2026-11-01 11:00');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertFalse($invoice->approved_automatically);
        $this->assertCount(0, $this->smtp->sent);
    }

    /* ---- Total variance ------------------------------------------------ */

    /**
     * Last month: $5,059.20 (505920).
     *
     * @return array<string, array{0: int, 1: bool, 2: string|null}>
     */
    public static function totals(): array
    {
        return [
            'same' => [505920, true, null],
            '+10%' => [556512, true, null],
            'exactly +25% is allowed' => [632400, true, null],
            'just over +25%' => [632401, false, 'Total is more than 25% higher than last month.'],
            '+38%' => [700000, false, 'Total is 38% higher than last month.'],
            'exactly -25% is allowed' => [379440, true, null],
            'just under -25%' => [379439, false, 'Total is more than 25% lower than last month.'],
            '-50%' => [252960, false, 'Total is 50% lower than last month.'],
        ];
    }

    #[DataProvider('totals')]
    public function test_total_variance(int $total, bool $safe, ?string $message): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $invoice = $this->octoberDraft($plan);
        Invoice::query()->whereKey($invoice->id)->update(['total_minor' => $total]);

        $result = app(InvoiceAutomationSafety::class)->evaluate($invoice->fresh(), CarbonImmutable::parse('2026-11-01'));

        $this->assertSame($safe, $result->isSafe());
        $this->assertSame($message, collect($result->reasons)->firstWhere('code', 'total_variance')['message'] ?? null);
    }

    public function test_a_zero_total_last_month_is_handled_without_dividing(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $baseline = $this->issuedBaseline($plan);
        DB::table('invoices')->where('id', $baseline->id)->update(['total_minor' => 0]);
        $invoice = $this->octoberDraft($plan);
        $safety = app(InvoiceAutomationSafety::class);
        $today = CarbonImmutable::parse('2026-11-01');

        $result = $safety->evaluate($invoice, $today);
        $this->assertTrue($result->has(AutomationReason::TotalVariance));
        $this->assertSame("Last month's total was zero, so this total needs a look.", $result->reasons[0]['message']);

        Invoice::query()->whereKey($invoice->id)->update(['total_minor' => 0]);
        $this->assertTrue($safety->evaluate($invoice->fresh(), $today)->isSafe(), 'zero then zero is unchanged');
    }

    public function test_an_unusual_total_is_held_with_a_reason_in_needs_you(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);
        $plan->lines()->where('description', 'Office Rent')->update(['unit_price_minor' => 300000]);

        $this->runAt('2026-11-01 09:05');

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertSame([AutomationReason::TotalVariance->value], $this->holdCodes($invoice));

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('needsYou.0.attention.label', 'Needs review')
                ->where('needsYou.0.attention.reason', 'Total is 38% higher than last month.')
                ->where('needsYou.0.attention.action', 'Review'));

        /* The owner looks, decides it is right, and approves: that overrides the warning. */
        $approved = app(ApproveInvoiceAction::class)->handle($invoice, $invoice->version, $this->owner);
        $this->assertSame(InvoiceStatus::Approved, $approved->status);
        $this->assertFalse($approved->approved_automatically);
        $this->assertSame($this->owner->id, $approved->approved_by);
    }

    /* ---- Hours variance ------------------------------------------------ */

    /**
     * Each CSR billed 160h last month.
     *
     * @return array<string, array{0: int, 1: bool, 2: string|null}>
     */
    public static function hours(): array
    {
        return [
            'same' => [16000, true, null],
            'exactly +30% is allowed' => [20800, true, null],
            'just over +30%' => [20801, false, "Muhammad Usman Ghani's hours are more than 30% higher than last month."],
            '230h is 44% higher' => [23000, false, "Muhammad Usman Ghani's hours are 44% higher than last month."],
            'exactly -30% is allowed' => [11200, true, null],
            '80h is 50% lower' => [8000, false, "Muhammad Usman Ghani's hours are 50% lower than last month."],
        ];
    }

    #[DataProvider('hours')]
    public function test_each_persons_hours_are_compared_with_last_month(int $usmanHours, bool $safe, ?string $message): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, self::FULL_MONTH);
        $invoice = $this->enterHours($this->octoberDraft($plan), [0 => $usmanHours] + self::FULL_MONTH);

        $result = app(InvoiceAutomationSafety::class)->evaluate($invoice, CarbonImmutable::parse('2026-11-03'));

        $this->assertSame($safe, ! $result->has(AutomationReason::HoursVariance));
        $this->assertSame($message, collect($result->reasons)->firstWhere('code', 'hours_variance')['message'] ?? null);
    }

    public function test_people_are_matched_by_identity_not_row_position(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, [0 => 10000] + self::FULL_MONTH);
        $invoice = $this->enterHours($this->octoberDraft($plan), [0 => 10000] + self::FULL_MONTH);

        /* Reorder the rows (Usman last, Faizan first): the same hours must still match up. */
        $invoice->lines()->where('position', 0)->update(['position' => 99]);
        $invoice->lines()->where('position', 4)->update(['position' => 0]);
        $invoice->lines()->where('position', 99)->update(['position' => 4]);

        $this->assertFalse(app(InvoiceAutomationSafety::class)->evaluate($invoice->fresh(), CarbonImmutable::parse('2026-11-03'))->has(AutomationReason::HoursVariance));
    }

    public function test_lines_without_a_team_profile_are_matched_by_name(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $baseline = $this->issuedBaseline($plan, self::FULL_MONTH);
        $invoice = $this->enterHours($this->octoberDraft($plan), [1 => 30000] + self::FULL_MONTH);
        DB::table('invoice_lines')->whereIn('invoice_id', [$baseline->id, $invoice->id])->update(['person_id' => null]);

        $result = app(InvoiceAutomationSafety::class)->evaluate($invoice->fresh(), CarbonImmutable::parse('2026-11-03'));

        $this->assertSame(["Abdul Wahab's hours are 88% higher than last month."], array_column(array_filter($result->reasons, fn ($r) => $r['code'] === 'hours_variance'), 'message'));
    }

    public function test_zero_previous_hours_and_new_people_need_a_look(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, [2 => 0] + self::FULL_MONTH);
        $invoice = $this->enterHours($this->octoberDraft($plan), [2 => 2000] + self::FULL_MONTH);
        $invoice->lines()->create(['position' => 5, 'person_id' => $this->team['Abdul Rehman']->id, 'description' => 'Abdul Rehman (cover)', 'role_label' => 'CSR', 'quantity_centi' => 0, 'unit_price_minor' => 496]);
        $invoice->lines()->where('position', 4)->delete();
        $invoice->lines()->create(['position' => 6, 'person_id' => null, 'description' => 'New Hire', 'role_label' => 'CSR', 'quantity_centi' => 4000, 'unit_price_minor' => 400]);

        $messages = array_column(array_filter(
            app(InvoiceAutomationSafety::class)->evaluate($invoice->fresh(), CarbonImmutable::parse('2026-11-03'))->reasons,
            fn ($reason) => $reason['code'] === 'hours_variance',
        ), 'message');

        $this->assertContains('Faheem had no hours last month.', $messages);
        $this->assertContains("New Hire wasn't on last month's invoice.", $messages);
        $this->assertContains("Muhammad Faizan was on last month's invoice but isn't on this one.", $messages);
    }

    public function test_unusual_hours_hold_an_auto_send_invoice(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, self::FULL_MONTH);
        $plan = $this->autoSend($plan);

        $this->runAt('2026-11-01 00:05');
        $this->enterHours($this->october($plan), [0 => 23000] + self::FULL_MONTH);
        $this->runAt('2026-11-03 09:05');

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertContains(AutomationReason::HoursVariance->value, $this->holdCodes($invoice));
    }

    /* ---- Hourly workflow: missing and late hours ------------------------ */

    public function test_hours_completed_before_the_send_date_follow_auto_send(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, self::FULL_MONTH);
        $plan = $this->autoSend($plan);

        $this->runAt('2026-11-01 00:05');
        $this->enterHours($this->october($plan), [0 => 15000] + self::FULL_MONTH);
        $this->runAt('2026-11-02 09:05');
        $this->assertSame(InvoiceStatus::ReadyToReview, $this->october($plan)->status, 'not before the planned send date');

        $this->runAt('2026-11-03 09:05');
        $this->assertSame(InvoiceStatus::Approved, $this->october($plan)->status);
        $this->assertTrue($this->october($plan)->approved_automatically);
    }

    public function test_hours_completed_before_the_send_date_wait_for_review_in_review_mode(): void
    {
        $plan = $this->csrTeam();

        $this->runAt('2026-11-01 00:05');
        $this->enterHours($this->october($plan), self::FULL_MONTH);
        $this->runAt('2026-11-03 09:05');

        $this->assertSame(InvoiceStatus::ReadyToReview, $this->october($plan)->status);
        $this->assertContains('CSR Team for October is ready to review.', $this->messages());
    }

    public function test_missing_hours_on_the_send_date_are_never_sent(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, self::FULL_MONTH);
        $plan = $this->autoSend($plan);

        $this->runAt('2026-11-01 00:05');
        $this->enterHours($this->october($plan), [0 => 16000, 1 => 16000]);
        $this->runAt('2026-11-03 09:05');
        $this->runAt('2026-11-03 12:00');

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->status);
        $this->assertContains(AutomationReason::MissingHours->value, $this->holdCodes($invoice));
        $this->assertSame(1, $this->notifications('invoice_auto_send_paused'), 'told once');
        $this->assertContains('CSR Team for October needs your review. CSR Team still needs hours before it can be sent (3 people missing).', $this->messages());
    }

    public function test_hours_finished_after_the_send_date_are_not_sent_automatically(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, self::FULL_MONTH);
        $plan = $this->autoSend($plan);

        $this->runAt('2026-11-01 00:05');
        $this->runAt('2026-11-03 09:05');

        $this->travelTo('2026-11-05 10:00');
        $this->enterHours($this->october($plan), self::FULL_MONTH);
        $this->runAt('2026-11-05 10:15');
        $this->runAt('2026-11-06 10:15');

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status, 'late data never triggers an email');
        $this->assertCount(0, $this->smtp->sent);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('needsYou.0.attention.kind', 'ready_to_send')
                ->where('needsYou.0.attention.label', 'Ready to send')
                ->where('needsYou.0.attention.reason', 'Hours were completed after the scheduled send date. Review and send this invoice.'));

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page->where('invoice.automation_reasons.0.code', 'late_hours'));
    }

    public function test_an_invoice_first_seen_after_its_send_date_waits_for_the_owner(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);

        /* The scheduler was down on Nov 1 and comes back on Nov 4. */
        $this->runAt('2026-11-04 09:05');

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertSame([AutomationReason::SendDateMissed->value], $this->holdCodes($invoice));
    }

    /* ---- Auto-send prerequisites --------------------------------------- */

    public function test_email_must_be_connected_and_tested_for_automatic_sending(): void
    {
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);

        $this->runAt('2026-11-01 09:05');

        $invoice = $this->october($plan);
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertSame(['mail_not_connected'], $this->holdCodes($invoice));
        $this->assertSame('Connect email delivery before this invoice can be sent automatically.', $invoice->automation_hold[0]['message']);
        $this->assertNull($invoice->number, 'nothing was issued');
    }

    public function test_untested_email_settings_do_not_count_as_connected(): void
    {
        $this->connectMail(['verified_at' => null]);
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $invoice = $this->octoberDraft($this->autoSend($plan));

        $this->assertTrue(app(InvoiceAutomationSafety::class)->evaluate($invoice, CarbonImmutable::parse('2026-11-01'))->has(AutomationReason::MailNotConnected));
    }

    public function test_an_invalid_recipient_is_held(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $invoice = $this->octoberDraft($plan);
        $invoice->forceFill(['bill_to' => [...$invoice->bill_to, 'billing_email' => 'not-an-email']])->save();

        $this->assertTrue(app(InvoiceAutomationSafety::class)->evaluate($invoice->fresh(), CarbonImmutable::parse('2026-11-01'))->has(AutomationReason::RecipientInvalid));
    }

    /* ---- Concurrency --------------------------------------------------- */

    public function test_two_workers_approve_once(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);
        $invoice = $this->octoberDraft($plan);
        $this->travelTo('2026-11-01 09:05');
        $now = CarbonImmutable::now();

        $first = app(ProcessAutoSendInvoiceAction::class)->handle(Invoice::query()->findOrFail($invoice->id), $now);
        $second = app(ProcessAutoSendInvoiceAction::class)->handle(Invoice::query()->findOrFail($invoice->id), $now);

        $this->assertSame('approved', $first);
        $this->assertNull($second);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_APPROVED->value)->where('subject_id', $invoice->id)->count());
    }

    public function test_the_same_reminder_cannot_be_sent_twice(): void
    {
        $reminders = app(OwnerReminders::class);

        $this->assertTrue($reminders->once($this->workspace, 'test:key', 'invoice_ready_to_review', 'Ready', 'Hello', '/x'));
        $this->assertFalse($reminders->once($this->workspace, 'test:key', 'invoice_ready_to_review', 'Ready', 'Hello', '/x'));
        $this->assertSame(1, $this->notifications());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('billing_reminders')->insert(['workspace_id' => $this->workspace->id, 'reminder_key' => 'test:key']);
    }

    /* ---- Reminders ----------------------------------------------------- */

    public function test_review_reminders_upcoming_due_day_and_one_follow_up(): void
    {
        $plan = $this->devAndOffice();

        foreach (['2026-10-28 10:00', '2026-10-29 10:00', '2026-10-29 11:00', '2026-11-01 00:05', '2026-11-01 08:00', '2026-11-02 10:00', '2026-11-02 18:00', '2026-11-03 10:00', '2026-11-10 10:00'] as $moment) {
            $this->runAt($moment);
        }

        $this->assertSame([
            'Dev & Office for October will be ready to review on Nov 1.',
            'Dev & Office for October is ready to review.',
            'Dev & Office for October is ready to review.',
        ], $this->messages());
        $this->assertSame(['invoice_upcoming', 'invoice_ready_to_review', 'invoice_ready_to_review'], $this->owner->notifications()->reorder()->oldest()->get()->pluck('data.type')->all());
        $this->assertSame('Still waiting for you', $this->owner->notifications()->reorder()->oldest()->get()->last()->data['title']);
        $this->assertSame(3, DB::table('billing_reminders')->count());
    }

    public function test_no_follow_up_once_the_owner_has_acted(): void
    {
        $plan = $this->devAndOffice();
        $this->runAt('2026-11-01 00:05');
        $invoice = $this->october($plan);
        app(ApproveInvoiceAction::class)->handle($invoice, $invoice->version, $this->owner);

        $this->runAt('2026-11-02 10:00');

        $this->assertSame(0, $this->notifications('invoice_ready_to_review') - 1, 'only the due-day reminder');
    }

    public function test_hourly_review_reminders_say_hours_are_missing(): void
    {
        $plan = $this->csrTeam();

        $this->runAt('2026-11-01 00:05');
        $this->runAt('2026-11-03 10:00');
        $this->runAt('2026-11-04 10:00');
        $this->runAt('2026-11-05 10:00');

        $this->assertSame([
            'CSR Team · October hours are ready to complete.',
            'CSR Team for October still needs hours before it can be sent.',
            'CSR Team for October still needs hours before it can be sent.',
        ], $this->messages());
        $this->assertSame(InvoiceStatus::NeedsHours, $this->october($plan)->status);
    }

    public function test_reminders_use_the_workspace_calendar(): void
    {
        $this->workspace->update(['timezone' => 'Asia/Karachi']);
        $this->devAndOffice();

        $this->runAt('2026-10-28 23:30', 'Asia/Karachi');
        $this->assertSame(0, $this->notifications());

        $this->runAt('2026-10-29 00:30', 'Asia/Karachi');
        $this->assertSame(1, $this->notifications('invoice_upcoming'));
    }

    /* ---- Rollout, plan changes and pausing ------------------------------ */

    public function test_existing_invoices_are_never_picked_up_by_automation(): void
    {
        $this->connectMail();
        $plan = $this->autoSend($this->devAndOffice());
        $old = app(GenerateInvoiceFromPlanAction::class)->handle($plan, app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse('2026-10-03')), $this->owner);

        $this->runAt('2026-10-03 10:05');
        $this->runAt('2026-10-04 10:05');

        $old->refresh();
        $this->assertSame(InvoiceStatus::ReadyToReview, $old->status);
        $this->assertNull($old->automation_checked_at);
        $this->assertSame(0, $this->notifications());
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_changing_the_plan_mode_only_affects_future_invoices(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $this->runAt('2026-11-01 00:05');
        $this->autoSend($plan);

        $this->runAt('2026-11-01 09:05');

        $invoice = $this->october($plan);
        $this->assertSame(DeliveryMode::ReviewBeforeSending, $invoice->delivery_mode, 'the invoice keeps the mode it was prepared with');
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertNull($invoice->automation_checked_at);
    }

    public function test_pausing_stops_automatic_sending_but_not_an_approved_invoice(): void
    {
        $this->connectMail();
        $plan = $this->devAndOffice();
        $this->issuedBaseline($plan);
        $plan = $this->autoSend($plan);

        $this->runAt('2026-11-01 00:05');
        app(SetBillingPlanPausedAction::class)->handle($plan, true, $this->owner);
        $this->runAt('2026-11-01 09:05');
        $this->assertSame(InvoiceStatus::ReadyToReview, $this->october($plan)->status, 'not sent while paused');

        /* An invoice already approved goes ahead unless the owner cancels it. */
        $invoice = $this->october($plan);
        app(ApproveInvoiceAction::class)->handle($invoice, $invoice->version, $this->owner);
        $this->travelTo('2026-11-01 10:30');
        $this->artisan('billing:dispatch-approved-invoices')->run();
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    /* ---- Needs you, plan card and access ------------------------------- */

    public function test_needs_you_is_ordered_by_what_matters_most(): void
    {
        $this->connectMail();
        $dev = $this->devAndOffice();
        $csr = $this->csrTeam();
        $this->issuedBaseline($dev);
        $this->autoSend($dev)->lines()->where('description', 'Office Rent')->update(['unit_price_minor' => 300000]);
        $this->runAt('2026-11-01 09:05');

        $retainer = $this->savePlanFor('Retainer');
        $this->runAt('2026-11-02 09:05');

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('needsYou.0.attention.kind', 'needs_hours')
                ->where('needsYou.0.attention.reason', '5 people missing')
                ->where('needsYou.0.attention.action', 'Enter hours')
                ->where('needsYou.1.attention.kind', 'safety_review'));

        $this->assertNotNull($csr);
        $this->assertNotNull($retainer);
    }

    public function test_an_auto_send_invoice_waiting_for_its_send_date_needs_nothing_yet(): void
    {
        $this->connectMail();
        $plan = $this->csrTeam();
        $this->issuedBaseline($plan, self::FULL_MONTH);
        $plan = $this->autoSend($plan);
        $this->runAt('2026-11-01 00:05');
        $invoice = $this->enterHours($this->october($plan), self::FULL_MONTH);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('needsYou', []));

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page->where('invoice.sends_automatically', true));
    }

    public function test_the_plan_card_explains_automatic_sending(): void
    {
        $this->connectMail();
        $plan = $this->autoSend($this->devAndOffice());

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->assertInertia(fn (Assert $page) => $page->where('plans.0.first_invoice_needs_review', true));

        $this->issuedBaseline($plan);

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->assertInertia(fn (Assert $page) => $page->where('plans.0.first_invoice_needs_review', false));
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest client' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_automation_gives_no_one_else_finance_access(UserRole $role): void
    {
        $this->connectMail();
        $plan = $this->autoSend($this->devAndOffice());
        $this->runAt('2026-11-01 09:05');
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->actingAs($user)->get(route('workspace.invoices.index', $this->workspace))->assertForbidden();
        $this->actingAs($user)->get(route('workspace.invoices.show', [$this->workspace, $this->october($plan)]))->assertForbidden();
        $this->assertSame(0, $user->notifications()->count(), 'reminders go to the owner only');
    }

    public function test_automation_stays_inside_each_workspace(): void
    {
        $this->devAndOffice();
        $otherOwner = User::factory()->create();
        Workspace::factory()->ownedBy($otherOwner)->create();

        $this->runAt('2026-11-01 00:05');

        $this->assertSame(0, $otherOwner->notifications()->count());
        $this->assertSame(1, Invoice::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(0, Invoice::query()->where('workspace_id', '!=', $this->workspace->id)->count());
    }

    /** A second fixed plan for RocketFlood that sends on the 2nd. */
    private function savePlanFor(string $name): BillingPlan
    {
        return app(SaveBillingPlanAction::class)->handle($this->rocketFlood, null, new StoreBillingPlanData(
            name: $name,
            currency: Currency::USD,
            pricing_mode: PricingMode::Fixed,
            generation_day: 2,
            send_day: 2,
            billing_period: BillingPeriod::PreviousMonth,
            due_in_days: 15,
            delivery_mode: DeliveryMode::ReviewBeforeSending,
            reminder_days_before: 0,
            lines: [['person_id' => null, 'description' => 'Retainer', 'role_label' => null, 'unit_price_minor' => 100000]],
            adjustments: [],
        ), $this->owner);
    }
}
