<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Models\User;
use App\Modules\Meetings\Data\TranscriptStatus;
use App\Modules\Meetings\Jobs\TranscribeMeetingJob;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingTranscript;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class StoreMeetingRecording
{
    public function handle(Meeting $meeting, User $actor, UploadedFile $recording): MeetingTranscript
    {
        $transcript = $this->transcriptFor($meeting);
        $disk = Storage::disk((string) config('transcription.disk'));

        if ($transcript->audio_path !== null) {
            $disk->delete($transcript->audio_path);
        }

        $path = $recording->store("meeting-recordings/{$meeting->workspace_id}/{$meeting->id}", [
            'disk' => (string) config('transcription.disk'),
        ]);

        $transcript->update([
            'status' => TranscriptStatus::Queued,
            'audio_path' => $path,
            'audio_bytes' => $recording->getSize(),
            'failure_reason' => null,
            'uploaded_by' => $actor->id,
        ]);

        TranscribeMeetingJob::dispatch($transcript->id);

        return $transcript->refresh();
    }

    private function transcriptFor(Meeting $meeting): MeetingTranscript
    {
        return MeetingTranscript::query()->firstOrCreate(
            ['meeting_id' => $meeting->id],
            [
                'workspace_id' => $meeting->workspace_id,
                'project_id' => $meeting->project_id,
                'status' => TranscriptStatus::AwaitingAudio,
            ],
        );
    }
}
