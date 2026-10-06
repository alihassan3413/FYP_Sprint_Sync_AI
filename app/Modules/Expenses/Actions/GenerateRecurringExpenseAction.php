<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Actions;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records this month's occurrence of a monthly expense once its day has come
 * (the workspace's calendar). Only the current month: stopped or
 * not-yet-started months are never backfilled. UNIQUE(recurring_expense_id,
 * period_start) keeps it to one occurrence however often this runs. The
 * occurrence copies the amount and details as they are now; later edits never
 * change it.
 */
final class GenerateRecurringExpenseAction
{
    public function handle(RecurringExpense $recurring, CarbonImmutable $now): ?Expense
    {
        if ($recurring->stopped_at !== null) {
            return null;
        }

        $today = $now->setTimezone($recurring->workspace->timezone)->startOfDay();
        $month = $today->startOfMonth();
        $due = $month->setDay($recurring->day_of_month);

        /* Calendar dates compared as Y-m-d strings, so no timezone can shift them. */
        $dueDate = $due->toDateString();

        if ($today->lessThan($due) || $dueDate < $recurring->starts_on->toDateString() || ($recurring->ends_on !== null && $dueDate > $recurring->ends_on->toDateString())) {
            return null;
        }

        $existing = fn () => Expense::query()->where('recurring_expense_id', $recurring->id)->whereDate('period_start', $month->toDateString())->first();

        if ($found = $existing()) {
            return $found;
        }

        try {
            return Expense::query()->forceCreate([
                'workspace_id' => $recurring->workspace_id,
                'category' => $recurring->category,
                'description' => $recurring->description,
                'vendor' => $recurring->vendor,
                'amount_minor' => $recurring->amount_minor,
                'currency' => $recurring->currency,
                'expense_date' => $due->toDateString(),
                'recurring_expense_id' => $recurring->id,
                'period_start' => $month->toDateString(),
                'client_id' => $recurring->client_id,
                'project_id' => $recurring->project_id,
                'notes' => $recurring->notes,
                'created_by' => null,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $existing() ?? throw $exception;
        }
    }
}
