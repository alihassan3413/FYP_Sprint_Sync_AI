<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\AdjustmentType;
use App\Modules\Billing\Models\Concerns\BelongsToFrozenInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot of a fee, tax or discount: as configured (value) and as
 * calculated for this invoice (amount_minor, negative for discounts).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property AdjustmentKind $kind
 * @property AdjustmentType $type
 * @property string $label
 * @property int $value
 * @property int $amount_minor
 */
final class InvoiceAdjustment extends Model
{
    use BelongsToFrozenInvoice;

    public $timestamps = false;

    protected $fillable = ['position', 'kind', 'type', 'label', 'value', 'amount_minor'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => AdjustmentKind::class,
            'type' => AdjustmentType::class,
            'value' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
