<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Carbon\CarbonImmutable;

/**
 * Which recorded time to total. Dates are business dates (the workspace's
 * calendar) and inclusive; an entry belongs to the range of its period_start.
 * Voided entries never count; adjustments always do (they are signed).
 */
final readonly class TimeQuery
{
    public function __construct(
        public int $workspaceId,
        public ?int $personId = null,
        public ?int $projectId = null,
        public ?int $taskId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public bool $billableOnly = false,
    ) {}
}
