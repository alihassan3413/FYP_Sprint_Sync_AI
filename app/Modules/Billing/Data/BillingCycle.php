<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Carbon\CarbonImmutable;

/**
 * One month of a recurring invoice: the period it bills, the day its draft is
 * generated and the day it is planned to be sent. Calendar dates in the
 * workspace's timezone. A generated invoice will snapshot all four, so the
 * plan's later edits never move an existing invoice's dates.
 */
final readonly class BillingCycle
{
    public function __construct(
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public CarbonImmutable $generationDate,
        public CarbonImmutable $sendDate,
    ) {}

    /** Has the planned send date arrived? Review plans still wait for the owner. */
    public function isSendDue(CarbonImmutable $today): bool
    {
        return ! $today->startOfDay()->lessThan($this->sendDate);
    }
}
