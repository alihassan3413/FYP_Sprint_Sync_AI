<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team member (person_id set) or a plain item (person_id null) on a
 * recurring invoice. unit_price_minor is the monthly amount for fixed plans
 * and the hourly rate for hourly plans.
 *
 * @property int $id
 * @property int $billing_plan_id
 * @property int $position
 * @property int|null $person_id
 * @property string $description
 * @property string|null $role_label
 * @property int $unit_price_minor
 */
final class BillingPlanLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['position', 'person_id', 'description', 'role_label', 'unit_price_minor'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'unit_price_minor' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
