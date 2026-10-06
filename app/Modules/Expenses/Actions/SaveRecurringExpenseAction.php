<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\Money;
use App\Modules\Expenses\Data\ExpenseCategory;
use App\Modules\Expenses\Models\RecurringExpense;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Creates or changes a monthly expense. Months already recorded keep the
 * amount they were recorded with; the change applies from the next one.
 * Stopping it records no further months.
 */
final class SaveRecurringExpenseAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  array{category: ExpenseCategory, description: string, vendor: ?string, amount_minor: int, currency: Currency, day_of_month: int, starts_on: CarbonImmutable, ends_on: ?CarbonImmutable, client_id: ?int, project_id: ?int, notes: ?string}  $details
     */
    public function handle(Workspace $workspace, ?RecurringExpense $recurring, array $details, User $actor): RecurringExpense
    {
        $recurring ??= (new RecurringExpense)->forceFill(['workspace_id' => $workspace->id, 'created_by' => $actor->id]);
        $recurring->forceFill([
            ...$details,
            'starts_on' => $details['starts_on']->toDateString(),
            'ends_on' => $details['ends_on']?->toDateString(),
        ])->save();

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::EXPENSE_RECURRING_SAVED,
            "{$actor->name} saved the monthly expense \"{$recurring->description}\": ".Money::of($recurring->amount_minor, $recurring->currency)->formatted()." on day {$recurring->day_of_month}.",
            $recurring,
            ['amount_minor' => $recurring->amount_minor, 'currency' => $recurring->currency->value, 'day_of_month' => $recurring->day_of_month],
        );

        return $recurring;
    }

    public function stop(RecurringExpense $recurring, User $actor): void
    {
        if (RecurringExpense::query()->whereKey($recurring->getKey())->whereNull('stopped_at')->update(['stopped_at' => now()]) === 1) {
            $this->auditLogger->handle($recurring->workspace, null, $actor, AuditAction::EXPENSE_RECURRING_STOPPED, "{$actor->name} stopped the monthly expense \"{$recurring->description}\".", $recurring);
        }
    }
}
