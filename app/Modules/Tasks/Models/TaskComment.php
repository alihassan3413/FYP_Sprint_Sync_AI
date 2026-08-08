<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Models;

use App\Models\User;
use App\Modules\Tasks\Database\Factories\TaskCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $body
 * @property int $task_id
 * @property int $user_id
 */
final class TaskComment extends Model
{
    /** @use HasFactory<TaskCommentFactory> */
    use HasFactory;

    protected $fillable = [
        'body',
        'task_id',
        'user_id',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAuthoredBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    protected static function newFactory(): TaskCommentFactory
    {
        return TaskCommentFactory::new();
    }
}
