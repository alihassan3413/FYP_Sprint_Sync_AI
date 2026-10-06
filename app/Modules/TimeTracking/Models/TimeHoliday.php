<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A paid holiday on one business date, for everyone (no people attached) or
 * the people listed. A credit toward paid hours, never tracked time.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property Carbon $date
 * @property string $name
 * @property int $credit_seconds
 * @property Carbon|null $removed_at
 */
final class TimeHoliday extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'credit_seconds' => 'integer', 'removed_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'time_holiday_people', 'time_holiday_id', 'person_id');
    }
}
