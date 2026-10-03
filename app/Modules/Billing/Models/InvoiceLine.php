<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Models\Concerns\BelongsToFrozenInvoice;
use App\Modules\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property int|null $quantity_centi
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
