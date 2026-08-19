<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Database\Factories\WorkspaceFactory;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property int $owner_id
 */
final class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'settings',
        'is_active',
        'owner_id',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_users')
            ->withPivot(['role', 'workspace_role_id'])
            ->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(WorkspaceRole::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function inviteLinks(): HasMany
    {
        return $this->hasMany(WorkspaceInviteLink::class);
    }

    public function activeInviteLink(): ?WorkspaceInviteLink
    {
        return $this->inviteLinks()->active()->latest('id')->first();
    }

    public function pendingInvitations(): HasMany
    {
        return $this->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @var array<int, User|null>
     */
    private array $resolvedMemberships = [];

    /**
     * @var array<int, WorkspaceRole|null>
     */
    private array $resolvedCustomRoles = [];

    public function hasMember(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->exists();
    }

    public function roleFor(User $user): ?UserRole
    {
        $membership = $this->membershipFor($user);

        return $membership === null
            ? null
            : UserRole::tryFrom($membership->pivot->role);
    }

    public function userHasAtLeast(User $user, UserRole $minimum): bool
    {
        return $this->roleFor($user)?->atLeast($minimum) ?? false;
    }

    public function allows(User $user, WorkspacePermission $permission): bool
    {
        if ($this->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $this->customRoleFor($user)?->grants($permission->value) ?? false;
    }

    public function customRoleFor(User $user): ?WorkspaceRole
    {
        $membership = $this->membershipFor($user);
        $roleId = $membership?->pivot->workspace_role_id;

        if ($roleId === null) {
            return null;
        }

        return $this->resolvedCustomRoles[$roleId] ??= $this->roles()->whereKey($roleId)->first();
    }

    /**
     * @return array<int, string>
     */
    public function grantedPermissionsFor(User $user): array
    {
        if ($this->userHasAtLeast($user, UserRole::ADMIN)) {
            return WorkspacePermission::values();
        }

        $customRole = $this->customRoleFor($user);

        if ($customRole === null) {
            return [];
        }

        return array_values(array_filter(
            WorkspacePermission::values(),
            fn (string $permission) => $customRole->grants($permission),
        ));
    }

    private function membershipFor(User $user): ?User
    {
        $key = $user->getKey();

        if (array_key_exists($key, $this->resolvedMemberships)) {
            return $this->resolvedMemberships[$key];
        }

        return $this->resolvedMemberships[$key] = $this->users()->whereKey($key)->first();
    }

    public function forgetResolvedMembership(): void
    {
        $this->resolvedMemberships = [];
        $this->resolvedCustomRoles = [];
    }

    public function accessibleProjectsFor(User $user): HasMany
    {
        $query = $this->projects();

        if (! $this->userHasAtLeast($user, UserRole::ADMIN) && ! $this->allows($user, WorkspacePermission::ProjectsView)) {
            $query->whereHas('members', fn (Builder $members) => $members->whereKey($user->id));
        }

        return $query;
    }

    public function managedProjectsFor(User $user): HasMany
    {
        return $this->projects()->whereHas('managers', fn (Builder $managers) => $managers->whereKey($user->id));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }
}
