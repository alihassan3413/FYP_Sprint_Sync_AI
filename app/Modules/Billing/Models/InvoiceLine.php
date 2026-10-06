<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Models\Concerns\BelongsToFrozenInvoice;
use App\Modules\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A snapshot line. person_id is a reference only; description and role_label
 * are what the invoice prints. quantity_centi is null on an hourly line whose
 * hours are not entered yet (0 means "entered: nothing to bill").
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property int|null $person_id
 * @property string $description
 * @property string|null $role_label
 * @property int|null $quantity_centi billed hours (hundredths): what the client pays for
 * @property int|null $tracked_seconds recorded billable time for the period (time tracking), the reference
 * @property int|null $tracked_quantity_centi tracked_seconds in hundredths of an hour
 * @property string|null $quantity_reason why billed hours differ from recorded
 * @property int|null $quantity_set_by
 * @property Carbon|null $quantity_set_at
 * @property int $unit_price_minor
 * @property int|null $amount_minor
 */
final class InvoiceLine extends Model
{
    use BelongsToFrozenInvoice;

    public $timestamps = false;

    protected $fillable = ['position', 'person_id', 'description', 'role_label', 'quantity_centi', 'unit_price_minor', 'amount_minor'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity_centi' => 'integer',
            'tracked_seconds' => 'integer',
            'tracked_quantity_centi' => 'integer',
            'quantity_set_at' => 'datetime',
            'unit_price_minor' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
