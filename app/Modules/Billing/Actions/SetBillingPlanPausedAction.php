<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\BillingPlan;

/**
 * Pauses or resumes a recurring invoice. The change is a conditional update
 * (only from the opposite state), so a double click or retry changes nothing
 * the second time and is not audited twice.
 */
final class SetBillingPlanPausedAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(BillingPlan $plan, bool $paused, User $actor): BillingPlan
    {
        $changed = BillingPlan::query()
            ->whereKey($plan->getKey())
            ->when($paused, fn ($query) => $query->whereNull('paused_at'), fn ($query) => $query->whereNotNull('paused_at'))
            ->update(['paused_at' => $paused ? now() : null]);

        $plan->refresh();

        if ($changed === 1) {
            $this->auditLogger->handle(
                $plan->workspace,
                null,
                $actor,
                $paused ? AuditAction::BILLING_PLAN_PAUSED : AuditAction::BILLING_PLAN_RESUMED,
                $paused
                    ? "{$actor->name} paused the recurring invoice \"{$plan->name}\" for {$plan->client->name}."
                    : "{$actor->name} resumed the recurring invoice \"{$plan->name}\" for {$plan->client->name}.",
                $plan,
                ['client' => $plan->client->name, 'plan' => $plan->name],
            );
        }

        return $plan;
    }
}
