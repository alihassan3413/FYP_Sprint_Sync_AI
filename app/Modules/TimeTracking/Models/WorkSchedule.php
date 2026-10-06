<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One version of an expected schedule (person_id NULL = the workspace's).
 * days NULL = no schedule from this version on.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $person_id
 * @property list<int>|null $days ISO weekdays, 1 = Monday
 * @property string|null $start_time "18:00"
 * @property string|null $end_time "02:00" (next day when not after start)
 * @property string $timezone
 * @property Carbon $effective_from
 */
final class WorkSchedule extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['days' => 'array', 'effective_from' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Schedule versions are never edited. Save a new version.'));
    }

    public function isEmpty(): bool
    {
        return empty($this->days) || $this->start_time === null || $this->end_time === null;
    }
}
