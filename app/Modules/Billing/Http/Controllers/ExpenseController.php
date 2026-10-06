<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\Money;
use App\Modules\Billing\Http\Requests\FinancePeriodRequest;
use App\Modules\Billing\Http\Requests\RecordExpenseRequest;
use App\Modules\Billing\Http\Requests\RecurringExpenseRequest;
use App\Modules\Billing\Http\Requests\VoidExpenseRequest;
use App\Modules\Billing\Support\MoneyFormatter;
use App\Modules\Expenses\Actions\GenerateRecurringExpenseAction;
use App\Modules\Expenses\Actions\RecordExpenseAction;
use App\Modules\Expenses\Actions\SaveRecurringExpenseAction;
use App\Modules\Expenses\Actions\VoidExpenseAction;
use App\Modules\Expenses\Data\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\RecurringExpense;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Expenses: money going out, each in its own currency.
 */
final class ExpenseController
{
    public function index(FinancePeriodRequest $request, Workspace $workspace): Response
    {
        abort_unless($request->user()->can('viewAny', [Expense::class, $workspace]), 403);

        $period = $request->period();
        $expenses = $workspace->expenses()
            ->with(['client:id,name', 'project:id,name'])
            ->whereDate('expense_date', '>=', $period['from']->toDateString())
            ->whereDate('expense_date', '<=', $period['to']->toDateString())
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        $totals = [];
        foreach ($expenses->reject->isVoided() as $expense) {
            $totals[$expense->currency->value] = ($totals[$expense->currency->value] ?? Money::zero($expense->currency))->plus($expense->money());
        }
        ksort($totals);

        return Inertia::render('expenses/index', [
            'period' => ['key' => $period['key'], 'from' => $period['from']->toDateString(), 'to' => $period['to']->toDateString()],
            'expenses' => $expenses->map(fn (Expense $expense) => [
                'public_id' => $expense->public_id,
                'category' => $expense->category->label(),
                'description' => $expense->description,
                'vendor' => $expense->vendor,
                'amount' => $expense->money()->formatted(),
                'currency' => $expense->currency->value,
                'expense_date' => $expense->expense_date->toDateString(),
                'client' => $expense->client?->name,
                'project' => $expense->project?->name,
                'recurring' => $expense->recurring_expense_id !== null,
                'notes' => $expense->notes,
                'voided' => $expense->isVoided(),
                'void_reason' => $expense->void_reason,
            ])->values(),
            'totals' => array_values(array_map(fn (Money $total) => ['currency' => $total->currency->value, 'amount' => $total->formatted()], $totals)),
            'recurring' => $workspace->recurringExpenses()
                ->with(['client:id,public_id,name', 'project:id,public_id,name'])
                ->orderByRaw('stopped_at is not null')
                ->orderBy('description')
                ->get()
                ->map(fn (RecurringExpense $recurring) => [
                    'public_id' => $recurring->public_id,
                    'category' => $recurring->category->value,
                    'category_label' => $recurring->category->label(),
                    'description' => $recurring->description,
                    'vendor' => $recurring->vendor,
                    'amount' => MoneyFormatter::quantity($recurring->amount_minor),
                    'amount_formatted' => Money::of($recurring->amount_minor, $recurring->currency)->formatted(),
                    'currency' => $recurring->currency->value,
                    'day_of_month' => $recurring->day_of_month,
                    'starts_on' => $recurring->starts_on->toDateString(),
                    'ends_on' => $recurring->ends_on?->toDateString(),
                    'client' => $recurring->client?->public_id,
                    'client_name' => $recurring->client?->name,
                    'project' => $recurring->project?->public_id,
                    'project_name' => $recurring->project?->name,
                    'notes' => $recurring->notes,
                    'stopped' => $recurring->stopped_at !== null,
                ])->values(),
            'categories' => ExpenseCategory::options(),
            'currencies' => Currency::options(),
            'clients' => $workspace->clients()->orderBy('name')->get(['public_id', 'name'])->map->only(['public_id', 'name'])->values(),
            'projects' => $workspace->projects()->orderBy('name')->get(['public_id', 'name'])->map->only(['public_id', 'name'])->values(),
            'today' => CarbonImmutable::now($workspace->timezone)->toDateString(),
            'canManage' => $request->user()->can('manage', [Expense::class, $workspace]),
        ]);
    }

    public function store(RecordExpenseRequest $request, Workspace $workspace, RecordExpenseAction $action): RedirectResponse
    {
        $expense = $action->handle($workspace, $request->expense(), $request->validated('idempotency_key'), $request->user());

        return back()->with('success', "Expense of {$expense->money()->formatted()} recorded.");
    }

    public function void(VoidExpenseRequest $request, Workspace $workspace, Expense $expense, VoidExpenseAction $action): RedirectResponse
    {
        $action->handle($expense, $request->validated('reason'), $request->user());

        return back()->with('success', 'Expense voided. It stays in the history but no longer counts.');
    }

    public function storeRecurring(RecurringExpenseRequest $request, Workspace $workspace, SaveRecurringExpenseAction $action, GenerateRecurringExpenseAction $generate): RedirectResponse
    {
        $recurring = $action->handle($workspace, null, $request->recurring(), $request->user());
        $generate->handle($recurring, CarbonImmutable::now());

        return back()->with('success', 'Monthly expense saved. It is recorded each month on its day.');
    }

    public function updateRecurring(RecurringExpenseRequest $request, Workspace $workspace, RecurringExpense $recurringExpense, SaveRecurringExpenseAction $action): RedirectResponse
    {
        abort_if($recurringExpense->stopped_at !== null, 409, 'This monthly expense is stopped.');

        $action->handle($workspace, $recurringExpense, $request->recurring(), $request->user());

        return back()->with('success', 'Monthly expense updated. Months already recorded keep their amount.');
    }

    public function stopRecurring(Request $request, Workspace $workspace, RecurringExpense $recurringExpense, SaveRecurringExpenseAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('manage', [Expense::class, $workspace]), 403);

        $action->stop($recurringExpense, $request->user());

        return back()->with('success', 'Monthly expense stopped. Months already recorded stay.');
    }
}
