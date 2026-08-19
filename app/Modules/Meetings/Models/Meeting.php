<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Models;

use App\Models\User;
use App\Modules\Meetings\Database\Factories\MeetingFactory;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property Carbon $scheduled_at
 * @property int $duration_minutes
 * @property string|null $meeting_link
 * @property string|null $join_token
 * @property int $project_id
 * @property int $workspace_id
 * @property int $created_by
 */
final class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'scheduled_at',
        'duration_minutes',
        'meeting_link',
        'join_token',
        'project_id',
        'workspace_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transcript(): HasOne
    {
        return $this->hasOne(MeetingTranscript::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function hasParticipant(User $user): bool
    {
        return $this->participants()->where('user_id', $user->id)->exists();
    }

    public function joinUrl(): string
    {
        return route('meetings.join', ['token' => $this->join_token]);
    }

    public function hasValidJoinLink(): bool
    {
        return $this->meeting_link !== null && Str::startsWith($this->meeting_link, ['http://', 'https://']);
    }

    public function scopePast(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->whereRaw(
            "datetime({$table}.scheduled_at, '+' || {$table}.duration_minutes || ' minutes') < ?",
            [now()->toDateTimeString()],
        );
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->whereRaw(
            "datetime({$table}.scheduled_at, '+' || {$table}.duration_minutes || ' minutes') >= ?",
            [now()->toDateTimeString()],
        );
    }

    protected static function newFactory(): MeetingFactory
    {
        return MeetingFactory::new();
    }
}
