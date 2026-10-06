<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Contracts\ExchangeRateProvider;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\FxRate;
use App\Modules\Billing\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Rates the owner entered. The rate for a date is the latest one dated on or
 * before it (newest entry wins on the same date). A rate entered for the
 * other direction is inverted exactly (to 12 decimal places). Nothing is
 * guessed: no rate on or before the date means null ("Exchange rate needed").
 */
final class ManualExchangeRates implements ExchangeRateProvider
{
    public const INVERSE_SCALE = 12;

    public function rate(int $workspaceId, Currency $from, Currency $to, CarbonImmutable $date): ?FxRate
    {
        if ($from === $to) {
            return new FxRate($from, $to, '1', $date->toDateString(), 'same');
        }

        $direct = $this->latest($workspaceId, $from, $to, $date);
        $inverse = $this->latest($workspaceId, $to, $from, $date);

        /* Use whichever was set most recently for that date range. */
        $useInverse = $inverse !== null && ($direct === null || $inverse->rate_date->gt($direct->rate_date) || ($inverse->rate_date->eq($direct->rate_date) && $inverse->id > $direct->id));

        if ($useInverse) {
            $rate = BigDecimal::one()->dividedBy(BigDecimal::of($inverse->rate), self::INVERSE_SCALE, RoundingMode::HALF_UP)->stripTrailingZeros();

            return new FxRate($from, $to, (string) $rate, $inverse->rate_date->toDateString(), 'manual_inverse', $inverse->id);
        }

        return $direct === null ? null : new FxRate($from, $to, $direct->rate, $direct->rate_date->toDateString(), $direct->source, $direct->id);
    }

    private function latest(int $workspaceId, Currency $base, Currency $quote, CarbonImmutable $date): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('workspace_id', $workspaceId)
            ->where('base_currency', $base->value)
            ->where('quote_currency', $quote->value)
            ->whereDate('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();
    }
}
