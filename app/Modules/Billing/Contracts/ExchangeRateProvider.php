<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\FxRate;
use Carbon\CarbonImmutable;

/**
 * Where exchange rates come from. v1: rates the owner enters
 * (ManualExchangeRates). A market-data provider can implement this later
 * (it needs credentials and an approved dependency, so it isn't built yet).
 * Returns null rather than guessing when no rate applies.
 */
interface ExchangeRateProvider
{
    /** The rate in force on $date (the latest one on or before it). */
    public function rate(int $workspaceId, Currency $from, Currency $to, CarbonImmutable $date): ?FxRate;
}
