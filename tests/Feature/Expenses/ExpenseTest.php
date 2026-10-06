<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Client;
use App\Modules\Expenses\Actions\GenerateRecurringExpenseAction;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\RecurringExpense;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Money going out: one-off and monthly expenses, each in its own currency.
 */
final class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->owner = User::factory()->create(['name' => 'Ali Hassan']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['timezone' => 'Asia/Karachi']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.expenses.index', $this->workspace))
            ->post(route('workspace.expenses.store', $this->workspace), [
                'idempotency_key' => (string) Str::uuid(),
                'category' => 'office_rent',
                'description' => 'Office rent',
                'amount' => '300,000',
                'currency' => 'PKR',
                'expense_date' => '2026-10-01',
                ...$data,
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveMonthly(array $data = []): RecurringExpense
    {
        $this->actingAs($this->owner)
            ->from(route('workspace.expenses.index', $this->workspace))
            ->post(route('workspace.expenses.recurring.store', $this->workspace), [
                'category' => 'software',
                'description' => 'Figma',
                'amount' => '45.00',
                'currency' => 'USD',
                'day_of_month' => 20,
                'starts_on' => '2026-10-01',
                ...$data,
            ])->assertSessionHasNoErrors();

        return RecurringExpense::query()->latest('id')->firstOrFail();
    }

    private function generate(RecurringExpense $recurring, string $now): ?Expense
    {
        return app(GenerateRecurringExpenseAction::class)->handle($recurring->fresh(), CarbonImmutable::parse($now));
    }

    public function test_the_owner_records_an_expense_in_its_own_currency(): void
    {
        $this->record()->assertSessionHasNoErrors();

        $expense = Expense::query()->sole();
        $this->assertSame(30000000, $expense->amount_minor);
        $this->assertSame('PKR', $expense->currency->value);
        $this->assertSame('2026-10-01', $expense->expense_date->toDateString());
        $this->assertSame($this->owner->id, $expense->created_by);
        $this->assertSame(26, strlen($expense->public_id));

        $log = AuditLog::query()->where('action', AuditAction::EXPENSE_RECORDED->value)->sole();
        $this->assertStringContainsString('PKR 300,000.00', $log->description);
    }

    public function test_a_double_submit_records_one_expense(): void
    {
        $key = (string) Str::uuid();

        $this->record(['idempotency_key' => $key]);
        $this->record(['idempotency_key' => $key]);

        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EXPENSE_RECORDED->value)->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): array
    {
        return [
            'zero' => [['amount' => '0'], 'amount'],
            'three decimals' => [['amount' => '10.555'], 'amount'],
            'negative' => [['amount' => '-5'], 'amount'],
            'scientific' => [['amount' => '1e3'], 'amount'],
            'unknown currency' => [['currency' => 'BTC'], 'currency'],
            'unknown category' => [['category' => 'fun'], 'category'],
            'future date' => [['expense_date' => '2026-10-16'], 'expense_date'],
            'no description' => [['description' => ''], 'description'],
            'no key' => [['idempotency_key' => ''], 'idempotency_key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalid')]
    public function test_expenses_are_validated(array $data, string $field): void
    {
        $this->record($data)->assertSessionHasErrors($field);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_today_is_the_workspace_calendar_day(): void
    {
        // 20:00 UTC on the 15th is already the 16th in Karachi.
        $this->travelTo('2026-10-15 20:00:00');

        $this->record(['expense_date' => '2026-10-16'])->assertSessionHasNoErrors();
    }

    public function test_an_expense_can_belong_to_a_client_and_project_of_this_workspace_only(): void
    {
        $client = Client::factory()->for($this->workspace)->create();
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $foreignClient = Client::factory()->create();

        $this->record(['client' => $foreignClient->public_id])->assertSessionHasErrors('client');
        $this->record(['client' => $client->public_id, 'project' => $project->public_id])->assertSessionHasNoErrors();

        $expense = Expense::query()->sole();
        $this->assertSame($client->id, $expense->client_id);
        $this->assertSame($project->id, $expense->project_id);
    }

    public function test_voiding_keeps_the_expense_but_stops_counting_it(): void
    {
        $this->record();
        $this->record(['amount' => '500', 'currency' => 'USD', 'description' => 'Ads']);
        $rent = Expense::query()->where('currency', 'PKR')->sole();

        $this->actingAs($this->owner)->post(route('workspace.expenses.void', [$this->workspace, $rent]), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('workspace.expenses.void', [$this->workspace, $rent]), ['reason' => 'entered twice'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('workspace.expenses.void', [$this->workspace, $rent]), ['reason' => 'again']);

        $rent->refresh();
        $this->assertTrue($rent->isVoided());
        $this->assertSame('entered twice', $rent->void_reason);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EXPENSE_VOIDED->value)->count());

        $this->actingAs($this->owner)->get(route('workspace.expenses.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->component('expenses/index')
                ->has('expenses', 2)
                ->where('totals', [['currency' => 'USD', 'amount' => '$500.00']]));
    }

    public function test_expenses_are_never_edited_or_deleted(): void
    {
        $this->record();
        $expense = Expense::query()->sole();

        try {
            $expense->update(['amount_minor' => 1]);
            $this->fail('An expense amount was edited.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $expense->delete();
    }

    public function test_totals_are_per_currency_and_never_added_together(): void
    {
        $this->record();
        $this->record(['amount' => '10000', 'currency' => 'USD', 'description' => 'Contractor']);
        $this->record(['amount' => '0.50', 'currency' => 'USD', 'description' => 'Fee']);
        $this->record(['expense_date' => '2026-09-30', 'description' => 'September rent']);

        $this->actingAs($this->owner)->get(route('workspace.expenses.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->has('expenses', 3)
                ->where('totals', [['currency' => 'PKR', 'amount' => 'PKR 300,000.00'], ['currency' => 'USD', 'amount' => '$10,000.50']]));

        $this->actingAs($this->owner)->get(route('workspace.expenses.index', [$this->workspace, 'period' => 'last_month']))
            ->assertInertia(fn (Assert $page) => $page->where('period.from', '2026-09-01')->has('expenses', 1));
    }

    public function test_a_monthly_expense_is_recorded_once_per_month_on_its_day(): void
    {
        $recurring = $this->saveMonthly();
        $this->assertSame(0, Expense::query()->count(), 'the 20th has not come yet');

        $this->assertNull($this->generate($recurring, '2026-10-19 12:00:00'));
        $first = $this->generate($recurring, '2026-10-20 12:00:00');
        $again = $this->generate($recurring, '2026-10-25 12:00:00');

        $this->assertTrue($first->is($again));
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame('2026-10-20', $first->expense_date->toDateString());
        $this->assertSame('2026-10-01', $first->period_start->toDateString());
        $this->assertSame(4500, $first->amount_minor);

        $this->artisan('expenses:generate-recurring')->assertSuccessful();
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EXPENSE_RECURRING_SAVED->value)->count());
    }

    public function test_the_occurrence_is_unique_in_the_database(): void
    {
        $recurring = $this->saveMonthly(['day_of_month' => 1]);
        $occurrence = Expense::query()->sole();

        $this->expectException(UniqueConstraintViolationException::class);
        Expense::query()->forceCreate([...$occurrence->only(['workspace_id', 'category', 'description', 'amount_minor', 'currency', 'recurring_expense_id']), 'expense_date' => '2026-10-02', 'period_start' => '2026-10-01']);
    }

    public function test_the_day_follows_the_workspace_timezone(): void
    {
        $recurring = $this->saveMonthly(['day_of_month' => 1, 'starts_on' => '2026-11-01']);

        // 19:30 UTC on Oct 31 is 00:30 on Nov 1 in Karachi.
        $this->assertNull($this->generate($recurring, '2026-10-31 18:59:00'));
        $this->assertSame('2026-11-01', $this->generate($recurring, '2026-10-31 19:30:00')?->expense_date->toDateString());
    }

    public function test_editing_a_monthly_expense_only_changes_later_months(): void
    {
        $recurring = $this->saveMonthly(['day_of_month' => 1]);
        $october = Expense::query()->sole();

        $this->actingAs($this->owner)->put(route('workspace.expenses.recurring.update', [$this->workspace, $recurring]), [
            'category' => 'software', 'description' => 'Figma (team)', 'amount' => '90', 'currency' => 'USD', 'day_of_month' => 1, 'starts_on' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(4500, $october->fresh()->amount_minor);
        $this->assertSame('Figma', $october->fresh()->description);

        $november = $this->generate($recurring, '2026-11-02 08:00:00');
        $this->assertSame(9000, $november->amount_minor);
        $this->assertSame('Figma (team)', $november->description);
    }

    public function test_a_stopped_or_ended_monthly_expense_records_nothing_more(): void
    {
        $stopped = $this->saveMonthly(['day_of_month' => 1]);
        $ended = $this->saveMonthly(['day_of_month' => 1, 'description' => 'Trial', 'ends_on' => '2026-10-31']);
        $this->assertSame(2, Expense::query()->count());

        $this->actingAs($this->owner)->post(route('workspace.expenses.recurring.stop', [$this->workspace, $stopped]))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('workspace.expenses.recurring.stop', [$this->workspace, $stopped]));

        $this->assertNull($this->generate($stopped, '2026-11-02 08:00:00'));
        $this->assertNull($this->generate($ended, '2026-11-02 08:00:00'));
        $this->assertSame(2, Expense::query()->count(), 'months already recorded stay');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::EXPENSE_RECURRING_STOPPED->value)->count());
    }

    public function test_earlier_months_are_never_backfilled(): void
    {
        $this->saveMonthly(['day_of_month' => 1, 'starts_on' => '2026-01-01']);

        $this->assertSame(['2026-10-01'], Expense::query()->pluck('expense_date')->map->toDateString()->all());
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_sees_and_manages_expenses(UserRole $role): void
    {
        $this->record();
        $expense = Expense::query()->sole();
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->actingAs($user)->get(route('workspace.expenses.index', $this->workspace))->assertForbidden();
        $this->record([], $user)->assertForbidden();
        $this->actingAs($user)->post(route('workspace.expenses.void', [$this->workspace, $expense]), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($user)->post(route('workspace.expenses.recurring.store', $this->workspace), [])->assertForbidden();

        $this->assertSame(1, Expense::query()->count());
        $this->assertFalse($expense->fresh()->isVoided());
    }

    public function test_other_workspaces_get_not_found(): void
    {
        $this->record();
        $expense = Expense::query()->sole();

        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.expenses.index', $this->workspace))->assertNotFound();
        $this->actingAs($outsider)->post(route('workspace.expenses.void', [$theirs, $expense]), ['reason' => 'x'])->assertNotFound();
        $this->assertFalse($expense->fresh()->isVoided());
    }
}
