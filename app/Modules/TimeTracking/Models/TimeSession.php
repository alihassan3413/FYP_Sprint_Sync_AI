<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A timer. While active, active_person_id = person_id (unique), which is
 * what limits a person to one active timer. Changed only through the timer
 * actions, with conditional updates on its state.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $person_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property string $project_name
 * @property string|null $task_title
 * @property TimerState $state
 * @property int|null $active_person_id
 * @property Carbon $started_at
 * @property Carbon|null $lunch_started_at while on lunch
 * @property string|null $pause_reason "idle" after an automatic idle pause
 * @property Carbon|null $segment_started_at when the current running stretch began (null while paused or stopped)
 * @property int $accumulated_seconds finished running stretches
 * @property int $longest_segment_seconds longest uninterrupted stretch so far
 * @property Carbon|null $stopped_at
 * @property Carbon|null $state_changed_at when it last started, paused, resumed or stopped
 * @property string $timezone
 * @property bool $billable
 * @property string|null $note
 * @property int|null $started_by
 */
final class TimeSession extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'state' => TimerState::class,
            'started_at' => 'datetime',
            'segment_started_at' => 'datetime',
            'lunch_started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'state_changed_at' => 'datetime',
            'accumulated_seconds' => 'integer',
            'longest_segment_seconds' => 'integer',
            'billable' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** The entry a stopped timer produced. */
    public function entry(): HasOne
    {
        return $this->hasOne(TimeEntry::class);
    }

    /** Seconds worked so far: finished stretches plus the running one. Paused time never counts. */
    public function elapsedSeconds(CarbonInterface $now): int
    {
        return $this->accumulated_seconds + $this->runningSeconds($now);
    }

    public function runningSeconds(CarbonInterface $now): int
    {
        if ($this->state !== TimerState::Running || $this->segment_started_at === null) {
            return 0;
        }

        return max(0, (int) $this->segment_started_at->diffInSeconds($now, false));
    }
}
