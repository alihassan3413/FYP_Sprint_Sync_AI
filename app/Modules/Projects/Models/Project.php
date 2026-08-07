<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Database\Factories\ProjectFactory;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }
}
