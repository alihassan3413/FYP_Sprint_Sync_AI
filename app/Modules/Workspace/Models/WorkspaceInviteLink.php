<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Workspace\Database\Factories\WorkspaceInviteLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $created_by
 * @property string $token
 * @property int $uses
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
final class WorkspaceInviteLink extends Model
{
    /** @use HasFactory<WorkspaceInviteLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'token',
        'uses',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'uses' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    protected static function newFactory(): WorkspaceInviteLinkFactory
    {
        return WorkspaceInviteLinkFactory::new();
    }
}
