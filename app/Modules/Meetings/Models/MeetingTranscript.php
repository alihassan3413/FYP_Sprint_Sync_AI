<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Models;

use App\Models\User;
use App\Modules\Meetings\Data\TranscriptSource;
use App\Modules\Meetings\Data\TranscriptStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $meeting_id
 * @property int $workspace_id
 * @property int $project_id
 * @property TranscriptStatus $status
 * @property TranscriptSource|null $source
 * @property string|null $audio_path
 * @property int|null $audio_bytes
 * @property string|null $text
 * @property string|null $language
 * @property int|null $confidence
 * @property bool $is_low_confidence
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $failure_reason
 * @property int $attempts
 * @property int|null $uploaded_by
 * @property Carbon|null $transcribed_at
 */
final class MeetingTranscript extends Model
{
    protected $fillable = [
        'meeting_id',
        'workspace_id',
        'project_id',
        'status',
        'source',
        'audio_path',
        'audio_bytes',
        'text',
        'language',
        'confidence',
        'is_low_confidence',
        'provider',
        'model',
        'failure_reason',
        'attempts',
        'uploaded_by',
        'transcribed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TranscriptStatus::class,
            'source' => TranscriptSource::class,
            'is_low_confidence' => 'boolean',
            'transcribed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hasAudio(): bool
    {
        return $this->audio_path !== null;
    }

    public function isRetryable(): bool
    {
        return $this->status === TranscriptStatus::Failed && $this->hasAudio();
    }

    public function scopeAwaitingTranscription(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [TranscriptStatus::AwaitingAudio->value, TranscriptStatus::Queued->value])
            ->whereNotNull('audio_path');
    }
}
