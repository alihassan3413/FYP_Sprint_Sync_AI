<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Modules\People\Database\Factories\DepartmentFactory;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $name
 * @property string $name_key
 */
final class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        self::saving(fn (Department $department) => $department->name_key = self::keyFor($department->name));
    }

    /**
     * What makes two department names "the same": case and surrounding or
     * repeated whitespace do not count.
     */
    public static function keyFor(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
