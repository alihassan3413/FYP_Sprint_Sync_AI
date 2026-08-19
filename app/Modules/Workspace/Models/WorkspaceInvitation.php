<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Workspace\Database\Factories\WorkspaceInvitationFactory;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property UserRole $role
 * @property int|null $workspace_role_id
 * @property string $token
 * @property int $workspace_id
 * @property int $invited_by
 * @property Carbon|null $accepted_at
 * @property Carbon|null $expires_at
 */
final class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'email',
        'role',
        'workspace_role_id',
        'token',
        'workspace_id',
        'invited_by',
        'accepted_at',
        'expires_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(WorkspaceRole::class, 'workspace_role_id');
    }

    /**
     * The role label shown to the invitee, including the custom role when one is attached.
     */
    public function roleLabel(): string
    {
        $customRole = $this->customRole;

        return $customRole === null
            ? $this->role->label()
            : "{$customRole->name} ({$this->role->label()})";
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    protected static function newFactory(): WorkspaceInvitationFactory
    {
        return WorkspaceInvitationFactory::new();
    }
}
