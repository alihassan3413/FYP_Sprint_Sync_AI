<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\Currency;
use App\Modules\Expenses\Data\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records a one-off expense in its own currency. A double submit (same
 * request key) returns the first expense.
 */
final class RecordExpenseAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  array{category: ExpenseCategory, description: string, vendor: ?string, amount_minor: int, currency: Currency, expense_date: CarbonImmutable, client_id: ?int, project_id: ?int, notes: ?string}  $details
     */
    public function handle(Workspace $workspace, array $details, string $idempotencyKey, User $actor): Expense
    {
        $existing = fn () => Expense::query()->where('workspace_id', $workspace->id)->where('idempotency_key', $idempotencyKey)->first();

        if ($found = $existing()) {
            return $found;
        }

        try {
            $expense = Expense::query()->forceCreate([
                'workspace_id' => $workspace->id,
                ...$details,
                'expense_date' => $details['expense_date']->toDateString(),
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $existing() ?? throw $exception;
        }

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::EXPENSE_RECORDED,
            "{$actor->name} recorded an expense: {$expense->description}, ".$expense->money()->formatted().'.',
            $expense,
            ['description' => $expense->description, 'amount_minor' => $expense->amount_minor, 'currency' => $expense->currency->value, 'date' => $expense->expense_date->toDateString()],
        );

        return $expense;
    }
}
