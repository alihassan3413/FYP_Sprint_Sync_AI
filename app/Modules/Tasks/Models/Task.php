<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Models;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Database\Factories\TaskFactory;
use App\Modules\Workspace\Models\Workspace;
use App\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property Carbon|null $due_date
 * @property int $project_id
 * @property int $workspace_id
 * @property int|null $assigned_to
 */
final class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'status',
        'due_date',
        'project_id',
        'workspace_id',
        'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_date' => 'date',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_to === $user->id;
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
