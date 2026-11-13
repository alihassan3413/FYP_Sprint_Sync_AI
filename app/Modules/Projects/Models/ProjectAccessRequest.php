<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone who opened a view-only share link and asked to add tasks.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string $status pending | approved | declined
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
final class ProjectAccessRequest extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
