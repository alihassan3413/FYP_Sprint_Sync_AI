<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Mail\MeetingScheduledMail;
use App\Models\User;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class CreateMeetingAction
{
    public function __construct(
        private readonly ResolveMeetingRecipients $resolveMeetingRecipients,
    ) {}

    public function handle(Project $project, User $creator, StoreMeetingData $data): Meeting
    {
        $meeting = $project->meetings()->create([
            'title' => $data->title,
            'description' => $data->description,
            'scheduled_at' => $data->scheduled_at,
            'duration_minutes' => $data->duration_minutes,
            'meeting_link' => $data->meeting_link,
            'workspace_id' => $project->workspace_id,
            'created_by' => $creator->id,
        ]);

        $this->notify($meeting, $creator);

        return $meeting;
    }

    private function notify(Meeting $meeting, User $actor): void
    {
        $recipients = $this->resolveMeetingRecipients->handle($meeting, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            foreach ($recipients as $recipient) {
                Mail::to($recipient->email)->queue(new MeetingScheduledMail(
                    projectName: $meeting->project->name,
                    meetingTitle: $meeting->title,
                    scheduledAt: $meeting->scheduled_at->format('F j, Y g:i A'),
                    durationMinutes: $meeting->duration_minutes,
                    agenda: $meeting->description,
                    joinUrl: $meeting->hasValidJoinLink() ? $meeting->meeting_link : null,
                    scheduledByName: $actor->name,
                ));
            }
        } catch (Throwable $e) {
            Log::error('Meeting scheduled notification dispatch failed', [
                'meeting_id' => $meeting->id,
                'exception' => $e,
            ]);
        }
    }
}
