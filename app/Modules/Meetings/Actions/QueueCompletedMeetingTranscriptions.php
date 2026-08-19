<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Modules\Meetings\Data\TranscriptStatus;
use App\Modules\Meetings\Jobs\TranscribeMeetingJob;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingTranscript;

final class QueueCompletedMeetingTranscriptions
{
    /**
     * @return array{detected: int, queued: int}
     */
    public function handle(): array
    {
        $detected = 0;
        $queued = 0;

        Meeting::query()
            ->past()
            ->orderBy('id')
            ->chunkById(100, function ($meetings) use (&$detected, &$queued) {
                foreach ($meetings as $meeting) {
                    $transcript = $this->transcriptFor($meeting);

                    if ($transcript->wasRecentlyCreated) {
                        $detected++;
                    }

                    if ($this->shouldQueue($transcript)) {
                        $transcript->update(['status' => TranscriptStatus::Queued]);

                        TranscribeMeetingJob::dispatch($transcript->id);

                        $queued++;
                    }
                }
            });

        return ['detected' => $detected, 'queued' => $queued];
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

    private function shouldQueue(MeetingTranscript $transcript): bool
    {
        return $transcript->status === TranscriptStatus::AwaitingAudio && $transcript->hasAudio();
    }
}
