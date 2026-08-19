<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Data\SprintStatus;
use App\Modules\Projects\Database\Factories\SprintFactory;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $goal
 * @property SprintStatus $status
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $committed_task_count
 * @property int|null $completed_task_count
 * @property int|null $carried_over_task_count
 * @property int $project_id
 * @property int $workspace_id
 */
final class Sprint extends Model
{
    /** @use HasFactory<SprintFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'goal',
        'status',
        'starts_on',
        'ends_on',
        'started_at',
        'completed_at',
        'committed_task_count',
        'completed_task_count',
        'carried_over_task_count',
        'project_id',
        'workspace_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SprintStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'committed_task_count' => 'integer',
            'completed_task_count' => 'integer',
            'carried_over_task_count' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SprintStatus::Active);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', SprintStatus::Completed);
    }

    public function scopePlanned(Builder $query): Builder
    {
        return $query->where('status', SprintStatus::Planned);
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    public function scopeForProjects(Builder $query, array $projectIds): Builder
    {
        return $query->whereIn('project_id', $projectIds);
    }

    public function isCurrent(): bool
    {
        $today = now()->startOfDay();

        return $this->starts_on->lessThanOrEqualTo($today) && $this->ends_on->greaterThanOrEqualTo($today);
    }

    public function isUpcoming(): bool
    {
        return $this->starts_on->greaterThan(now()->startOfDay());
    }

    /**
     * Running past its end date without having been completed.
     */
    public function isOverdue(): bool
    {
        return $this->status->isActive() && $this->ends_on->lessThan(now()->startOfDay());
    }

    public function totalDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * Days of the sprint that are behind us, clamped to the sprint window.
     */
    public function elapsedDays(): int
    {
        $today = now()->startOfDay();

        if ($today->lessThan($this->starts_on)) {
            return 0;
        }

        return min($this->totalDays(), (int) $this->starts_on->diffInDays($today) + 1);
    }

    public function daysRemaining(): int
    {
        return max(0, $this->totalDays() - $this->elapsedDays());
    }

    /**
     * How far through the calendar the sprint is, 0-100.
     */
    public function timeElapsedPercentage(): int
    {
        $total = $this->totalDays();

        return $total === 0 ? 100 : (int) round(($this->elapsedDays() / $total) * 100);
    }

    protected static function newFactory(): SprintFactory
    {
        return SprintFactory::new();
    }
}
