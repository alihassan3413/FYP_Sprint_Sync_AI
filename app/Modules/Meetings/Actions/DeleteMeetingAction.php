<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Mail\MeetingCancelledMail;
use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Meetings\Models\Meeting;
use App\Notifications\MeetingCancelledNotification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use App\Support\Time\UserTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class DeleteMeetingAction
{
    public function __construct(
        private readonly ResolveMeetingRecipients $resolveMeetingRecipients,
        private readonly NotificationPreferenceGate $preferences,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Meeting $meeting, User $actor): void
    {
        $recipients = $this->resolveMeetingRecipients->handle($meeting, $actor);
        $externalEmails = $this->resolveMeetingRecipients->externals($meeting)->pluck('email');
        $project = $meeting->project;
        $projectName = $project->name;
        $meetingTitle = $meeting->title;
        $scheduledAt = UserTime::format($meeting->scheduled_at, $actor->timezone);
        $scheduledAtUtc = $meeting->scheduled_at->toDateTimeString();
        $url = route('workspace.projects.show', ['workspace' => $project->workspace->slug, 'project' => $meeting->project_id]);

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::MEETING_CANCELLED,
            "{$actor->name} cancelled \"{$meetingTitle}\".",
            $meeting,
        );

        $meeting->delete();

        $this->notify($recipients, $externalEmails, $projectName, $meetingTitle, $scheduledAt, $scheduledAtUtc, $url, $actor);
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  Collection<int, string>  $externalEmails
     */
    private function notify(Collection $recipients, Collection $externalEmails, string $projectName, string $meetingTitle, string $scheduledAt, string $scheduledAtUtc, string $url, User $actor): void
    {
        if ($recipients->isEmpty() && $externalEmails->isEmpty()) {
            return;
        }

        try {
            $emailRecipients = $this->preferences->filter($recipients, NotificationType::MEETING_CANCELLED, NotificationChannel::EMAIL);

            foreach ($emailRecipients as $recipient) {
                Mail::to($recipient->email)->queue(new MeetingCancelledMail(
                    projectName: $projectName,
                    meetingTitle: $meetingTitle,
                    scheduledAt: $scheduledAt,
                    cancelledByName: $actor->name,
                ));
            }

            foreach ($externalEmails as $email) {
                Mail::to($email)->queue(new MeetingCancelledMail(
                    projectName: $projectName,
                    meetingTitle: $meetingTitle,
                    scheduledAt: $scheduledAt,
                    cancelledByName: $actor->name,
                ));
            }

            $inAppRecipients = $this->preferences->filter($recipients, NotificationType::MEETING_CANCELLED, NotificationChannel::IN_APP);

            Notification::send($inAppRecipients, new MeetingCancelledNotification(
                projectName: $projectName,
                meetingTitle: $meetingTitle,
                scheduledAtUtc: $scheduledAtUtc,
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
