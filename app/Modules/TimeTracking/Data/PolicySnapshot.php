<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use App\Modules\TimeTracking\Models\TimePolicy;
use Carbon\CarbonInterface;

/**
 * The time rules in force at one moment. Every feature defaults to off: with
 * no policy saved, time tracking behaves exactly as before.
 */
final readonly class PolicySnapshot
{
    public function __construct(
        public ?string $version = null,
        public ?CarbonInterface $effectiveFrom = null,
        public bool $idleEnabled = false,
        public ?int $idleSeconds = null,
        public ?int $weeklyTargetSeconds = null,
        public bool $lunchEnabled = false,
        public ?int $lunchAllowanceSeconds = null,
        public OvertimeMode $overtimeMode = OvertimeMode::TrackOnly,
        public bool $integrityEnabled = false,
    ) {}

    public static function fromModel(?TimePolicy $policy): self
    {
        if ($policy === null) {
            return new self;
        }

        return new self(
            version: $policy->public_id,
            effectiveFrom: $policy->effective_from,
            idleEnabled: $policy->idle_enabled && $policy->idle_seconds !== null,
            idleSeconds: $policy->idle_seconds,
            weeklyTargetSeconds: $policy->weekly_target_seconds,
            lunchEnabled: $policy->lunch_enabled && $policy->lunch_allowance_seconds !== null,
            lunchAllowanceSeconds: $policy->lunch_allowance_seconds,
            overtimeMode: $policy->overtime_mode,
            integrityEnabled: $policy->integrity_enabled,
        );
    }

    /**
     * For clients (the time page, SprintSync Desktop): what applies, nothing more.
     *
     * @return array{version: string|null, idle_enabled: bool, idle_seconds: int|null, weekly_target_seconds: int|null, lunch_enabled: bool, lunch_allowance_seconds: int|null, overtime_mode: string, integrity_enabled: bool}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'idle_enabled' => $this->idleEnabled,
            'idle_seconds' => $this->idleEnabled ? $this->idleSeconds : null,
            'weekly_target_seconds' => $this->weeklyTargetSeconds,
            'lunch_enabled' => $this->lunchEnabled,
            'lunch_allowance_seconds' => $this->lunchEnabled ? $this->lunchAllowanceSeconds : null,
            'overtime_mode' => $this->overtimeMode->value,
            'integrity_enabled' => $this->integrityEnabled,
        ];
    }
}
