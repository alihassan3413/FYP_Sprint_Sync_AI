<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Database\Factories\UserFactory;
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
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
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
        ];
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
