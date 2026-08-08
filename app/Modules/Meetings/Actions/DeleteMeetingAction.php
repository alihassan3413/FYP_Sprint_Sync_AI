<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Mail\MeetingCancelledMail;
use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Notifications\MeetingCancelledNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class DeleteMeetingAction
{
    public function __construct(
        private readonly ResolveMeetingRecipients $resolveMeetingRecipients,
    ) {}

    public function handle(Meeting $meeting, User $actor): void
    {
        $recipients = $this->resolveMeetingRecipients->handle($meeting, $actor);
        $projectName = $meeting->project->name;
        $meetingTitle = $meeting->title;
        $scheduledAt = $meeting->scheduled_at->format('F j, Y g:i A');
        $url = route('workspace.projects.show', ['workspace' => $meeting->project->workspace->slug, 'project' => $meeting->project_id]);

        $meeting->delete();

        $this->notify($recipients, $projectName, $meetingTitle, $scheduledAt, $url, $actor);
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function notify(Collection $recipients, string $projectName, string $meetingTitle, string $scheduledAt, string $url, User $actor): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            foreach ($recipients as $recipient) {
                Mail::to($recipient->email)->queue(new MeetingCancelledMail(
                    projectName: $projectName,
                    meetingTitle: $meetingTitle,
                    scheduledAt: $scheduledAt,
                    cancelledByName: $actor->name,
                ));
            }

            Notification::send($recipients, new MeetingCancelledNotification(
                projectName: $projectName,
                meetingTitle: $meetingTitle,
                cancelledByName: $actor->name,
                url: $url,
            ));
        } catch (Throwable $e) {
            Log::error('Meeting cancelled notification dispatch failed', [
                'meeting_title' => $meetingTitle,
                'exception' => $e,
            ]);
        }
    }
}
