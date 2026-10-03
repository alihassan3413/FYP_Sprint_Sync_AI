<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use InvalidArgumentException;
use OverflowException;

/**
 * An amount in a currency's minor unit (cents, paisa).
 *
 * Integers only, end to end: SQLite stores DECIMAL with NUMERIC affinity and can
 * hand back floats, so money is never persisted or calculated as a decimal.
 * Rounding happens in exactly one place, divideHalfUp(), and every operation
 * refuses to mix currencies.
 */
final readonly class Money
{
    public function __construct(
        public int $minor,
        public Currency $currency,
    ) {}

    public static function of(int $minor, Currency $currency): self
    {
        return new self($minor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor + $other->minor), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor - $other->minor), $this->currency);
    }

    /**
     * Multiply by a quantity given in hundredths (160h = 16000, 168.5h = 16850).
     */
    public function timesHundredths(int $quantityHundredths): self
    {
        return new self(self::divideHalfUp(self::checked($this->minor * $quantityHundredths), 100), $this->currency);
    }

    /**
     * A share of this amount, given in basis points (2% = 200).
     */
    public function percentage(int $basisPoints): self
    {
        return new self(self::divideHalfUp(self::checked($this->minor * $basisPoints), 10_000), $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    /**
     * Integer division rounding half away from zero: 0.5 cent rounds up for
     * positive amounts and down for negative ones, so a discount and the fee it
     * cancels round to the same magnitude.
     */
    public static function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Money can only be divided by a positive integer.');
        }

        $quotient = intdiv(abs($numerator), $denominator);
        $remainder = abs($numerator) % $denominator;

        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return $numerator < 0 ? -$quotient : $quotient;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency->value} with {$other->currency->value}.");
        }
    }

    /**
     * PHP silently turns an overflowing integer into a float. Money must never
     * become a float, so overflow is an error instead.
     */
    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw new OverflowException('Money amount is out of range.');
        }

        return $value;
    }
}
