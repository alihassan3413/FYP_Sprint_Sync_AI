<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Database\Factories\ProjectFactory;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $workspace_id
 */
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'workspace_id',
    ];

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    public function currentSprint(): ?Sprint
    {
        return $this->sprints()->current()->orderByDesc('starts_on')->first();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function boardColumns(): HasMany
    {
        return $this->hasMany(BoardColumn::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function managers(): BelongsToMany
    {
        return $this->members()->wherePivot('role', ProjectRole::MANAGER->value);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    public function roleFor(User $user): ?ProjectRole
    {
        $membership = $this->members()->whereKey($user->getKey())->first();

        return $membership === null
            ? null
            : ProjectRole::tryFrom($membership->pivot->role);
    }

    /**
     * Clients never hold project-level authority, whatever their project role says.
     * The workspace is only consulted once the project role itself passes, so a
     * partially selected project (id + name) still answers the common case.
     */
    public function userHasAtLeast(User $user, ProjectRole $minimum): bool
    {
        if (($this->roleFor($user)?->atLeast($minimum) ?? false) === false) {
            return false;
        }

        if (($this->attributes['workspace_id'] ?? null) === null) {
            return true;
        }

        return ! $this->workspace->isClient($user);
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }
}
