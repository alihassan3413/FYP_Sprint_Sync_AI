<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use InvalidArgumentException;

/**
 * Recomputes every amount on an invoice from its own quantities, prices and
 * adjustments using InvoiceCalculator. The server is the only place amounts
 * are decided; whatever a browser previewed is ignored.
 *
 * A line without hours yet has no amount and counts as nothing towards the
 * running total; the invoice is "complete" once every line has a quantity.
 */
final class InvoiceRecalculator
{
    public function __construct(private readonly InvoiceCalculator $calculator) {}

    /**
     * Updates line amounts, adjustment amounts and the invoice totals in place
     * (the caller saves the invoice). Returns whether every quantity is known.
     */
    public function recalculate(Invoice $invoice): bool
    {
        $lines = $invoice->lines;
        $adjustments = $invoice->adjustments;
        $complete = $lines->every(fn ($line) => $line->quantity_centi !== null);

        try {
            $totals = $this->calculator->calculate(
                $invoice->currency,
                $lines->map(fn ($line) => ['quantity' => $line->quantity_centi ?? 0, 'unit_price' => $line->unit_price_minor])->values()->all(),
                $adjustments->map(fn ($adjustment) => [
                    'kind' => $adjustment->kind,
                    'type' => $adjustment->type,
                    'value' => $adjustment->value,
                ])->values()->all(),
                partial: ! $complete,
            );
        } catch (InvalidArgumentException) {
            throw InvoiceException::negativeTotal();
        }

        foreach ($lines->values() as $index => $line) {
            $line->amount_minor = $line->quantity_centi === null ? null : $totals->lines[$index]->minor;

            if ($line->isDirty()) {
                $line->save();
            }
        }

        foreach ($adjustments->values() as $index => $adjustment) {
            $adjustment->amount_minor = $totals->adjustments[$index]->minor;

            if ($adjustment->isDirty()) {
                $adjustment->save();
            }
        }

        $invoice->forceFill([
            'subtotal_minor' => $totals->subtotal->minor,
            'adjustments_minor' => $totals->total->minor - $totals->subtotal->minor,
            'total_minor' => $totals->total->minor,
        ]);

        return $complete;
    }
}
