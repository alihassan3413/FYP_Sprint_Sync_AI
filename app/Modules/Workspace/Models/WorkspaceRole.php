<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Models;

use App\Modules\Workspace\Database\Factories\WorkspaceRoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property array<string, bool>|null $permissions
 * @property int $workspace_id
 */
final class WorkspaceRole extends Model
{
    /** @use HasFactory<WorkspaceRoleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'permissions',
        'workspace_id',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class, 'workspace_role_id');
    }

    public function grants(string $permission): bool
    {
        return ($this->permissions[$permission] ?? false) === true;
    }

    protected static function newFactory(): WorkspaceRoleFactory
    {
        return WorkspaceRoleFactory::new();
    }
}
