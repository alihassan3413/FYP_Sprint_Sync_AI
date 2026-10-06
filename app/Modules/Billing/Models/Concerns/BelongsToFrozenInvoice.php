<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models\Concerns;

use App\Modules\Billing\Data\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lines and adjustments of an approved or issued invoice are part of a
 * financial record: creating, changing or deleting them is refused at the
 * model. (Cancelling sending reopens the invoice first; only then can they change.)
 */
trait BelongsToFrozenInvoice
{
    protected static function bootBelongsToFrozenInvoice(): void
    {
        $guard = function (Model $child) {
            $status = InvoiceStatus::tryFrom((string) $child->invoice()->toBase()->value('status'));

            if ($status?->locksFinancials()) {
                throw new LogicException('The lines of an approved or issued invoice cannot change.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }
}
