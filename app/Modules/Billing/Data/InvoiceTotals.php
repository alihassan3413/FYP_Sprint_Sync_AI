<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

/**
 * The result of InvoiceCalculator: every figure an invoice prints, already
 * rounded, so the invoice, its PDF and its email can never disagree.
 */
final readonly class InvoiceTotals
{
    /**
     * @param  list<Money>  $lines  one amount per line, in input order
     * @param  list<Money>  $adjustments  one signed amount per adjustment, in input order
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public array $adjustments,
        public Money $total,
    ) {}
}
