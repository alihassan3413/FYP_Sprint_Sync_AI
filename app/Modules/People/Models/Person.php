<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Models\User;
use App\Modules\People\Database\Factories\PersonFactory;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone who works for the workspace, with or without a SprintSync login.
 *
 * workspace_id, user_id, department_id and public_id are not fillable: a
 * person is created through its workspace, and linking a user or choosing a
 * department goes through actions that check they belong to the same
 * workspace and record an audit entry.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property int|null $department_id
 * @property string $name
 * @property string|null $email
 * @property string|null $title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasPublicId;

    protected $table = 'people';

    protected $fillable = ['name', 'email', 'title'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }
}
