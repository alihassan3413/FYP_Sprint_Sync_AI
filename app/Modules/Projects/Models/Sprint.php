<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

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
 * @property Carbon $starts_on
 * @property Carbon $ends_on
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
        'starts_on',
        'ends_on',
        'project_id',
        'workspace_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
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

    protected static function newFactory(): SprintFactory
    {
        return SprintFactory::new();
    }
}
