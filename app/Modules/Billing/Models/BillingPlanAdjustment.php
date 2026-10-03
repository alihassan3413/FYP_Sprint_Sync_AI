<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\AdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fee, tax or discount on a recurring invoice. value is basis points for a
 * percentage (2% = 200) and minor units for a fixed amount; never a float.
 *
 * @property int $id
 * @property int $billing_plan_id
 * @property int $position
 * @property AdjustmentKind $kind
 * @property AdjustmentType $type
 * @property string $label
 * @property int $value
 */
final class BillingPlanAdjustment extends Model
{
    public $timestamps = false;

    protected $fillable = ['position', 'kind', 'type', 'label', 'value'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => AdjustmentKind::class,
            'type' => AdjustmentType::class,
            'value' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }
}
