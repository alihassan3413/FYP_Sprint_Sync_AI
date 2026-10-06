<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\TimeTracking\Data\OvertimeMode;
use App\Modules\TimeTracking\Data\PolicySnapshot;
use App\Modules\TimeTracking\Models\TimePolicy;
use App\Modules\TimeTracking\Support\TimePolicies;
use App\Modules\Workspace\Models\Workspace;

/**
 * Saves the owner's time rules as a new version that applies from now on.
 * Earlier time keeps the rules of its own time. Saving the same rules again
 * adds nothing. The audit entry lists what changed.
 */
final class SaveTimePolicyAction
{
    public function __construct(
        private readonly TimePolicies $policies,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    /**
     * @param  array{idle_enabled: bool, idle_seconds: int|null, weekly_target_seconds: int|null, lunch_enabled: bool, lunch_allowance_seconds: int|null, overtime_mode: OvertimeMode, integrity_enabled: bool}  $rules
     */
    public function handle(Workspace $workspace, array $rules, User $actor): PolicySnapshot
    {
        $current = $this->policies->at($workspace->id)->toArray();
        $next = [
            'idle_enabled' => $rules['idle_enabled'],
            'idle_seconds' => $rules['idle_enabled'] ? $rules['idle_seconds'] : null,
            'weekly_target_seconds' => $rules['weekly_target_seconds'],
            'lunch_enabled' => $rules['lunch_enabled'],
            'lunch_allowance_seconds' => $rules['lunch_enabled'] ? $rules['lunch_allowance_seconds'] : null,
            'overtime_mode' => $rules['overtime_mode']->value,
            'integrity_enabled' => $rules['integrity_enabled'],
        ];

        $changed = array_keys(array_filter($next, fn ($value, $key) => ($current[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return $this->policies->at($workspace->id);
        }

        $policy = TimePolicy::query()->forceCreate([
            'workspace_id' => $workspace->id,
            'effective_from' => now(),
            ...$next,
            'created_by' => $actor->id,
        ]);

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::TIME_POLICY_CHANGED,
            "{$actor->name} changed the time policy (".implode(', ', array_map(fn ($key) => str_replace('_', ' ', $key), $changed)).').',
            $policy,
            ['changed' => $changed, 'policy' => $next],
        );

        return PolicySnapshot::fromModel($policy);
    }
}
