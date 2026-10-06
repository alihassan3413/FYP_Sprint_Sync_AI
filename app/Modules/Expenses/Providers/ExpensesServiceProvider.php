<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Providers;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Policies\ExpensePolicy;
use App\Support\Modules\ModuleServiceProvider;

/**
 * Money going out. Domain only: the pages and routes live with the rest of
 * Finance in the Billing module.
 */
final class ExpensesServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Expenses';

    protected array $policies = [
        Expense::class => ExpensePolicy::class,
    ];
}
