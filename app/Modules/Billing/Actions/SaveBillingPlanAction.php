<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\StoreBillingPlanData;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Support\BillingPlanTotals;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or replaces a recurring invoice in one transaction.
 *
 * Lines and adjustments are replaced wholesale on every save, positioned by
 * list order, so a retried or double-submitted save produces the same rows
 * rather than duplicates; a failed save leaves nothing half written. The
 * UNIQUE(client_id, name) constraint stops a double-submitted create from
 * producing two plans. Saving identical content again writes no audit entry.
 */
final class SaveBillingPlanAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly BillingPlanTotals $totals,
    ) {}

    public function handle(Client $client, ?BillingPlan $plan, StoreBillingPlanData $data, User $actor): BillingPlan
    {
        $isNew = $plan === null;

        try {
            return DB::transaction(function () use ($client, $plan, $data, $actor, $isNew) {
                $plan ??= new BillingPlan;
                $before = $isNew ? null : $this->fingerprint($plan);

                $plan->forceFill(['workspace_id' => $client->workspace_id, 'client_id' => $client->id]);
                $plan->fill($data->attributes())->save();

                $plan->lines()->delete();
                $plan->adjustments()->delete();

                $plan->lines()->createMany(array_map(
                    fn (array $line, int $position) => [...$line, 'position' => $position],
                    $data->lines,
                    array_keys($data->lines),
                ));
                $plan->adjustments()->createMany(array_map(
                    fn (array $adjustment, int $position) => [...$adjustment, 'position' => $position],
                    $data->adjustments,
                    array_keys($data->adjustments),
                ));

                $plan->unsetRelation('lines')->unsetRelation('adjustments');

                if ($isNew || $before !== $this->fingerprint($plan)) {
                    $this->audit($client, $plan, $actor, $isNew);
                }

                return $plan;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => "{$client->name} already has a recurring invoice called \"{$data->name}\".",
            ]);
        }
    }

    private function audit(Client $client, BillingPlan $plan, User $actor, bool $isNew): void
    {
        $totals = $this->totals->forPlan($plan);

        $this->auditLogger->handle(
            $client->workspace,
            null,
            $actor,
            $isNew ? AuditAction::BILLING_PLAN_CREATED : AuditAction::BILLING_PLAN_UPDATED,
            $isNew
                ? "{$actor->name} set up the recurring invoice \"{$plan->name}\" for {$client->name}."
                : "{$actor->name} updated the recurring invoice \"{$plan->name}\" for {$client->name}.",
            $plan,
            [
                'client' => $client->name,
                'plan' => $plan->name,
                'pricing_mode' => $plan->pricing_mode->value,
                'delivery_mode' => $plan->delivery_mode->value,
                'lines' => $plan->lines->count(),
                'monthly_total_minor' => $totals?->total->minor,
                'currency' => $plan->currency->value,
            ],
        );
    }

    /**
     * Everything that defines the plan, to tell a real edit from a resubmit.
     */
    private function fingerprint(BillingPlan $plan): string
    {
        $plan->load(['lines', 'adjustments']);

        return json_encode([
            $plan->only(['name', 'generation_day', 'send_day', 'due_in_days', 'reminder_days_before']),
            $plan->currency->value,
            $plan->pricing_mode->value,
            $plan->billing_period->value,
            $plan->delivery_mode->value,
            $plan->lines->map->only(['position', 'person_id', 'description', 'role_label', 'unit_price_minor'])->all(),
            $plan->adjustments->map(fn ($adjustment) => [$adjustment->position, $adjustment->kind->value, $adjustment->type->value, $adjustment->label, $adjustment->value])->all(),
        ], JSON_THROW_ON_ERROR);
    }
}
