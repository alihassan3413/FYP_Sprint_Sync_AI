<?php

declare(strict_types=1);

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An immutable, opaque `public_id` (ULID) for models whose ids would otherwise
 * appear in URLs or props. The integer primary key stays internal.
 *
 * Route model binding uses public_id, and malformed values are rejected before
 * any query (so they 404). This is a privacy measure only: tenancy and policies
 * still decide who can see a record.
 *
 * The model's table needs `$table->ulid('public_id')->unique()`, and public_id
 * must not be fillable.
 *
 * @mixin Model
 */
trait HasPublicId
{
    use HasUlids;

    public static function bootHasPublicId(): void
    {
        static::updating(function (Model $model) {
            if ($model->isDirty('public_id')) {
                throw new LogicException(class_basename($model).' public ids cannot be changed.');
            }
        });
    }

    /**
     * HasUlids fills these on create. The integer primary key is left alone.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
