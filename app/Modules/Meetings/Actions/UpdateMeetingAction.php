<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Mail\MeetingUpdatedMail;
use App\Models\User;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Notifications\MeetingUpdatedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class UpdateMeetingAction
{
    private const NOTIFIABLE_FIELDS = ['title', 'description', 'scheduled_at', 'duration_minutes', 'meeting_link'];

    public function __construct(
        private readonly ResolveMeetingRecipients $resolveMeetingRecipients,
    ) {}

    public function handle(Meeting $meeting, User $actor, StoreMeetingData $data): Meeting
    {
        $meeting->update([
            'title' => $data->title,
            'description' => $data->description,
            'scheduled_at' => $data->scheduled_at,
            'duration_minutes' => $data->duration_minutes,
            'meeting_link' => $data->meeting_link,
        ]);

        if ($meeting->wasChanged(self::NOTIFIABLE_FIELDS)) {
            $this->notify($meeting, $actor);
        }

        return $meeting->refresh();
    }

    private function notify(Meeting $meeting, User $actor): void
    {
        $recipients = $this->resolveMeetingRecipients->handle($meeting, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            foreach ($recipients as $recipient) {
                Mail::to($recipient->email)->queue(new MeetingUpdatedMail(
                    projectName: $meeting->project->name,
                    meetingTitle: $meeting->title,
                    scheduledAt: $meeting->scheduled_at->format('F j, Y g:i A'),
                    durationMinutes: $meeting->duration_minutes,
                    agenda: $meeting->description,
                    joinUrl: $meeting->hasValidJoinLink() ? $meeting->meeting_link : null,
                    updatedByName: $actor->name,
                ));
            }

            Notification::send($recipients, new MeetingUpdatedNotification(
                projectName: $meeting->project->name,
                meetingTitle: $meeting->title,
                scheduledAt: $meeting->scheduled_at->format('F j, Y g:i A'),
                updatedByName: $actor->name,
                url: route('workspace.projects.show', ['workspace' => $meeting->project->workspace->slug, 'project' => $meeting->project_id]),
            ));
        } catch (Throwable $e) {
            Log::error('Meeting updated notification dispatch failed', [
                'meeting_id' => $meeting->id,
                'exception' => $e,
            ]);
        }
    }
}
