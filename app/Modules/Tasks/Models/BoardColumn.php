<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Models;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $position
 * @property bool $is_default
 * @property bool $is_done
 * @property int $project_id
 */
final class BoardColumn extends Model
{
    /** @use HasFactory<BoardColumnFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'position',
        'is_default',
        'is_done',
        'project_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_default' => 'boolean',
            'is_done' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected static function newFactory(): BoardColumnFactory
    {
        return BoardColumnFactory::new();
    }
}
