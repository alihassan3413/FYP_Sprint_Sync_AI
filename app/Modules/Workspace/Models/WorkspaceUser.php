<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property UserRole $role
 * @property int|null $workspace_role_id
 */
final class WorkspaceUser extends Model
{
    protected $table = 'workspace_users';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'workspace_role_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(WorkspaceRole::class, 'workspace_role_id');
    }
}
