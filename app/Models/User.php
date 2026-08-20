<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Time\UserTime;
use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $current_workspace_id
 * @property string|null $avatar_path
 * @property string|null $timezone
 * @property bool $is_super_admin
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'timezone',
        'password',
        'current_workspace_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Mirrors the column default so a freshly created model can answer
     * isSuperAdmin() without re-reading the row. Without it, strict models
     * throw on the missing attribute.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_super_admin' => false,
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'avatar_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Platform administrator, not a workspace role.
     *
     * Grants read access to the cross-tenant admin panel only. Workspace
     * membership and workspace policies are unaffected.
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    public function resolvedTimezone(): string
    {
        return UserTime::resolve($this->timezone);
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path !== null ? Storage::disk('public')->url($this->avatar_path) : null,
        );
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')
            ->withPivot(['role', 'workspace_role_id'])
            ->withTimestamps();
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function activeWorkspace(): ?Workspace
    {
        return $this->workspaces()->whereKey($this->current_workspace_id)->first();
    }

    /**
     * The workspace to drop this user into.
     *
     * Falls back to any workspace they belong to when `current_workspace_id`
     * is null or points at one they have since been removed from, and records
     * the choice, so a stale pointer never strands them on sign-in.
     */
    public function resolveActiveWorkspace(): ?Workspace
    {
        $workspace = $this->activeWorkspace();

        if ($workspace !== null) {
            return $workspace;
        }

        $workspace = $this->workspaces()->orderBy('workspaces.id')->first();

        if ($workspace !== null) {
            $this->forceFill(['current_workspace_id' => $workspace->id])->save();
        }

        return $workspace;
    }

    public function activeWorkspaceOrFail(): Workspace
    {
        $workspace = $this->activeWorkspace();

        if ($workspace === null) {
            throw WorkspaceException::noActiveWorkspace();
        }

        return $workspace;
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $workspace->hasMember($this);
    }

    public function roleIn(Workspace $workspace): ?UserRole
    {
        return $workspace->roleFor($this);
    }
}
