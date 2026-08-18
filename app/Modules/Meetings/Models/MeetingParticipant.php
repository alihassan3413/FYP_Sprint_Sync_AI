<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $meeting_id
 * @property int|null $user_id
 * @property string $email
 * @property string|null $name
 */
final class MeetingParticipant extends Model
{
    protected $fillable = [
        'meeting_id',
        'user_id',
        'email',
        'name',
    ];

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExternal(): bool
    {
        return $this->user_id === null;
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeExternal(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }
}
