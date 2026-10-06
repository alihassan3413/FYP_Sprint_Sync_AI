<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Brick\Math\BigDecimal;

/**
 * One exchange rate as used: "1 from = rate to", an exact decimal (string),
 * plus where it came from. Never a float.
 */
final readonly class FxRate
{
    public function __construct(
        public Currency $from,
        public Currency $to,
        public string $rate,
        public string $rateDate,
        public string $source,
        public ?int $exchangeRateId = null,
    ) {}

    public function decimal(): BigDecimal
    {
        return BigDecimal::of($this->rate);
    }
}
