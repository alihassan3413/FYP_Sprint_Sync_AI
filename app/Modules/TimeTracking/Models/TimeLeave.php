<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\LeaveType;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time off for one person over a date range. Paid leave credits
 * credit_seconds_per_day on each working day in the range (the person's
 * schedule days, Monday–Friday without one). Unpaid leave credits nothing.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $person_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property LeaveType $type
 * @property int $credit_seconds_per_day
 * @property string|null $note
 * @property Carbon|null $removed_at
 */
final class TimeLeave extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'type' => LeaveType::class, 'credit_seconds_per_day' => 'integer', 'removed_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
