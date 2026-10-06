<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Expenses\Models\Expense;

/**
 * Voids an expense recorded by mistake: kept on record with who, when and
 * why, and no longer counted. A second void changes nothing.
 */
final class VoidExpenseAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Expense $expense, string $reason, User $actor): Expense
    {
        $voided = Expense::query()->whereKey($expense->getKey())->whereNull('voided_at')->update([
            'voided_at' => now(),
            'voided_by' => $actor->id,
            'void_reason' => $reason,
            'updated_at' => now(),
        ]);

        $expense->refresh();

        if ($voided === 1) {
            $this->auditLogger->handle(
                $expense->workspace,
                null,
                $actor,
                AuditAction::EXPENSE_VOIDED,
                "{$actor->name} voided the expense \"{$expense->description}\": {$reason}",
                $expense,
                ['amount_minor' => $expense->amount_minor, 'currency' => $expense->currency->value, 'reason' => $reason],
            );
        }

        return $expense;
    }
}
