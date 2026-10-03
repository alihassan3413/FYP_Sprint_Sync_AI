<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_amounts_in_the_same_currency_add_and_subtract(): void
    {
        $sum = Money::of(496_000, Currency::USD)->plus(Money::of(9_920, Currency::USD));

        $this->assertSame(505_920, $sum->minor);
        $this->assertSame(495_000, $sum->minus(Money::of(10_920, Currency::USD))->minor);
    }

    public function test_different_currencies_are_never_added_together(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(200_000, Currency::USD)->plus(Money::of(4_000_000, Currency::PKR));
    }

    public function test_percentages_are_basis_points(): void
    {
        $this->assertSame(9_920, Money::of(496_000, Currency::USD)->percentage(200)->minor);
    }

    public function test_half_a_cent_rounds_away_from_zero(): void
    {
        $this->assertSame(1, Money::divideHalfUp(5, 10));
        $this->assertSame(0, Money::divideHalfUp(4, 10));
        $this->assertSame(2, Money::divideHalfUp(15, 10));
        $this->assertSame(-1, Money::divideHalfUp(-5, 10));
        $this->assertSame(0, Money::divideHalfUp(-4, 10));
    }

    public function test_multiplying_by_hundredths_returns_an_integer(): void
    {
        $amount = Money::of(555, Currency::USD)->timesHundredths(16_850);

        $this->assertIsInt($amount->minor);
        $this->assertSame(93_518, $amount->minor);
    }

    public function test_an_overflow_is_an_error_instead_of_a_silent_float(): void
    {
        $this->expectException(OverflowException::class);

        Money::of(PHP_INT_MAX, Currency::USD)->timesHundredths(200);
    }

    public function test_equality_needs_the_same_amount_and_currency(): void
    {
        $this->assertTrue(Money::of(100, Currency::USD)->equals(Money::of(100, Currency::USD)));
        $this->assertFalse(Money::of(100, Currency::USD)->equals(Money::of(100, Currency::PKR)));
        $this->assertFalse(Money::of(100, Currency::USD)->equals(Money::of(101, Currency::USD)));
    }

    public function test_every_currency_reports_its_minor_units(): void
    {
        foreach (Currency::cases() as $currency) {
            $this->assertSame(2, $currency->minorUnits());
        }
    }
}
