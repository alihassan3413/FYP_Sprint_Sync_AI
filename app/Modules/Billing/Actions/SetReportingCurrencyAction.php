<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Models\WorkspaceFinanceSettings;
use App\Modules\Workspace\Models\Workspace;

/**
 * The currency finance totals are shown in by default. Changing it converts
 * nothing that is stored; every record keeps its own amount and currency.
 */
final class SetReportingCurrencyAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, Currency $currency, User $actor): void
    {
        $previous = WorkspaceFinanceSettings::reportingCurrency($workspace->id);

        if ($previous === $currency) {
            return;
        }

        WorkspaceFinanceSettings::query()->updateOrCreate(['workspace_id' => $workspace->id], ['reporting_currency' => $currency]);

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::FINANCE_REPORTING_CURRENCY_CHANGED,
            "{$actor->name} changed the reporting currency from {$previous->value} to {$currency->value}.",
            null,
            ['from' => $previous->value, 'to' => $currency->value],
        );
    }
}
