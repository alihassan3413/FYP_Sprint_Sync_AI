<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\AdjustmentType;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\InvoiceTotals;
use App\Modules\Billing\Data\Money;
use InvalidArgumentException;

/**
 * Turns invoice lines and adjustments into rounded totals. Pure and
 * deterministic: the same input always produces the same cents.
 *
 * - A line is quantity × unit price, rounded half-up to the cent once.
 *   Quantities are hundredths (a fixed line is 100; 168.5 hours is 16850).
 * - Percentage adjustments apply to the subtotal, never to each other, and are
 *   rounded once each.
 * - The total is the subtotal plus the signed adjustments.
 */
final class InvoiceCalculator
{
    /**
     * @param  list<array{quantity: int, unit_price: int}>  $lines  quantity in hundredths, unit_price in minor units
     * @param  list<array{kind: AdjustmentKind, type: AdjustmentType, value: int}>  $adjustments  value in basis points or minor units
     * @param  bool  $partial  the lines are not all known yet (an hourly invoice still waiting for hours), so a
     *                         temporarily negative total is reported instead of rejected
     */
    public function calculate(Currency $currency, array $lines, array $adjustments = [], bool $partial = false): InvoiceTotals
    {
        $lineAmounts = array_map(fn (array $line) => $this->lineAmount($currency, $line), $lines);

        $subtotal = array_reduce($lineAmounts, fn (Money $carry, Money $amount) => $carry->plus($amount), Money::zero($currency));

        $adjustmentAmounts = array_map(fn (array $adjustment) => $this->adjustmentAmount($subtotal, $adjustment), $adjustments);

        $total = array_reduce($adjustmentAmounts, fn (Money $carry, Money $amount) => $carry->plus($amount), $subtotal);

        if ($total->isNegative() && ! $partial) {
            throw new InvalidArgumentException('Discounts cannot be larger than the subtotal.');
        }

        return new InvoiceTotals($lineAmounts, $subtotal, $adjustmentAmounts, $total);
    }

    /**
     * @param  array{quantity: int, unit_price: int}  $line
     */
    private function lineAmount(Currency $currency, array $line): Money
    {
        if ($line['quantity'] < 0 || $line['unit_price'] < 0) {
            throw new InvalidArgumentException('Line quantities and prices cannot be negative.');
        }

        return Money::of($line['unit_price'], $currency)->timesHundredths($line['quantity']);
    }

    /**
     * @param  array{kind: AdjustmentKind, type: AdjustmentType, value: int}  $adjustment
     */
    private function adjustmentAmount(Money $subtotal, array $adjustment): Money
    {
        if ($adjustment['value'] < 0) {
            throw new InvalidArgumentException('Adjustment values cannot be negative; use a discount instead.');
        }

        $amount = $adjustment['type'] === AdjustmentType::Percentage
            ? $subtotal->percentage($adjustment['value'])
            : Money::of($adjustment['value'], $subtotal->currency);

        return $adjustment['kind']->sign() < 0 ? $amount->negated() : $amount;
    }
}
