<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking\Concerns;

use App\Modules\TimeTracking\Actions\SaveTimePolicyAction;
use App\Modules\TimeTracking\Data\OvertimeMode;
use App\Modules\TimeTracking\Data\PolicySnapshot;

/**
 * Saves a time policy through the real action (as a new version from now).
 */
trait ConfiguresTimePolicy
{
    /**
     * @param  array<string, mixed>  $rules
     */
    protected function policy(array $rules = []): PolicySnapshot
    {
        return app(SaveTimePolicyAction::class)->handle($this->workspace, [
            'idle_enabled' => false,
            'idle_seconds' => null,
            'weekly_target_seconds' => null,
            'lunch_enabled' => false,
            'lunch_allowance_seconds' => null,
            'overtime_mode' => OvertimeMode::TrackOnly,
            'integrity_enabled' => false,
            ...$rules,
        ], $this->owner);
    }
}
