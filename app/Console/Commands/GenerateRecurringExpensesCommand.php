<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Expenses\Actions\GenerateRecurringExpenseAction;
use App\Modules\Expenses\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Records this month's occurrence of each monthly expense whose day has come.
 * Safe to run as often as the scheduler likes: one occurrence per month.
 */
final class GenerateRecurringExpensesCommand extends Command
{
    protected $signature = 'expenses:generate-recurring';

    protected $description = 'Record monthly expenses whose day has arrived';

    public function handle(GenerateRecurringExpenseAction $generate): int
    {
        $now = CarbonImmutable::now();
        $created = 0;

        RecurringExpense::query()->whereNull('stopped_at')->with('workspace')->lazyById()->each(function (RecurringExpense $recurring) use ($generate, $now, &$created) {
            $created += (int) ($generate->handle($recurring, $now)?->wasRecentlyCreated ?? false);
        });

        $this->info("Recorded {$created} expense(s).");

        return self::SUCCESS;
    }
}
