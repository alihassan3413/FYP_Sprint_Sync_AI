<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Models;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Recorded time in integer seconds (signed only for adjustments). Created by
 * the time actions; never edited afterwards except to void a manual entry or
 * an adjustment. Tracked time is corrected with adjustments, so the original
 * duration always stays visible.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $person_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property string $project_name
 * @property string|null $task_title
 * @property TimeEntrySource $source
 * @property int|null $time_session_id
 * @property int|null $batch_id
 * @property int|null $adjusts_entry_id
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int $duration_seconds
 * @property int|null $from_session_id the timer a lunch or kept-idle entry came from
 * @property int|null $paid_seconds lunch only: the paid part (up to the allowance)
 * @property int|null $outside_schedule_seconds tracked only: work outside the expected schedule
 * @property int|null $longest_segment_seconds longest uninterrupted stretch (tracked only)
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $timezone
 * @property bool $billable
 * @property string|null $note
 * @property string|null $idempotency_key
 * @property int|null $created_by
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
final class TimeEntry extends Model
{
    use HasPublicId;

    /** Only voiding may change an entry once it exists. */
    private const VOID_COLUMNS = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'source' => TimeEntrySource::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
            'voided_at' => 'datetime',
            'duration_seconds' => 'integer',
            'paid_seconds' => 'integer',
            'outside_schedule_seconds' => 'integer',
            'longest_segment_seconds' => 'integer',
            'billable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $entry) {
            if (array_diff(array_keys($entry->getDirty()), self::VOID_COLUMNS) !== []) {
                throw new LogicException('Time entries are not edited. Add an adjustment instead.');
            }
        });

        self::deleting(fn () => throw new LogicException('Time entries are never deleted. Void them instead.'));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('voided_at');
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function adjusts(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_entry_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(self::class, 'adjusts_entry_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
