<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Models\User;
use App\Modules\Meetings\Data\TranscriptSource;
use App\Modules\Meetings\Data\TranscriptStatus;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingTranscript;

final class StoreManualTranscript
{
    public function handle(Meeting $meeting, User $actor, string $text): MeetingTranscript
    {
        $transcript = MeetingTranscript::query()->firstOrCreate(
            ['meeting_id' => $meeting->id],
            [
                'workspace_id' => $meeting->workspace_id,
                'project_id' => $meeting->project_id,
                'status' => TranscriptStatus::AwaitingAudio,
            ],
        );

        $transcript->update([
            'status' => TranscriptStatus::Completed,
            'source' => TranscriptSource::Manual,
            'text' => trim($text),
            'confidence' => null,
            'is_low_confidence' => false,
            'provider' => null,
            'model' => null,
            'failure_reason' => null,
            'uploaded_by' => $actor->id,
            'transcribed_at' => now(),
        ]);

        return $transcript->refresh();
    }
}
