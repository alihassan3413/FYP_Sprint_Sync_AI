<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Mail\MeetingUpdatedMail;
use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Notifications\MeetingUpdatedNotification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use App\Support\Time\UserTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class UpdateMeetingAction
{
    private const NOTIFIABLE_FIELDS = ['title', 'description', 'scheduled_at', 'duration_minutes', 'meeting_link'];

    public function __construct(
        private readonly ResolveMeetingRecipients $resolveMeetingRecipients,
        private readonly NotificationPreferenceGate $preferences,
        private readonly RecordAuditLogAction $auditLogger,
        private readonly SyncMeetingParticipants $syncParticipants,
    ) {}

    public function handle(Meeting $meeting, User $actor, StoreMeetingData $data): Meeting
    {
        $participantChange = DB::transaction(function () use ($meeting, $data) {
            $meeting->update([
                'title' => $data->title,
                'description' => $data->description,
                'scheduled_at' => $data->scheduled_at,
                'duration_minutes' => $data->duration_minutes,
                'meeting_link' => $data->meeting_link,
            ]);

            return $this->syncParticipants->handle(
                $meeting,
                $meeting->project,
                $data->participant_user_ids,
                $data->participant_emails,
            );
        });

        $participantsChanged = $participantChange['added']->isNotEmpty() || $participantChange['removed']->isNotEmpty();

        if ($meeting->wasChanged(self::NOTIFIABLE_FIELDS) || $participantsChanged) {
            $this->auditLogger->handle(
                $meeting->project->workspace,
                $meeting->project,
                $actor,
                AuditAction::MEETING_UPDATED,
                "{$actor->name} updated \"{$meeting->title}\".",
                $meeting,
                ['changed_fields' => array_keys($meeting->getChanges())],
            );

            $this->notify($meeting, $actor);
        }

        return $meeting->refresh();
    }

    private function notify(Meeting $meeting, User $actor): void
    {
        $recipients = $this->resolveMeetingRecipients->handle($meeting, $actor);

        if ($recipients->isEmpty() && $this->resolveMeetingRecipients->externals($meeting)->isEmpty()) {
            return;
        }

        try {
            $emailRecipients = $this->preferences->filter($recipients, NotificationType::MEETING_UPDATED, NotificationChannel::EMAIL);

            foreach ($emailRecipients as $recipient) {
                Mail::to($recipient->email)->queue(new MeetingUpdatedMail(
                    projectName: $meeting->project->name,
                    meetingTitle: $meeting->title,
                    scheduledAt: UserTime::format($meeting->scheduled_at, $recipient->timezone),
                    durationMinutes: $meeting->duration_minutes,
                    agenda: $meeting->description,
                    joinUrl: $meeting->joinUrl(),
                    updatedByName: $actor->name,
                ));
            }

            foreach ($this->resolveMeetingRecipients->externals($meeting) as $external) {
                Mail::to($external->email)->queue(new MeetingUpdatedMail(
                    projectName: $meeting->project->name,
                    meetingTitle: $meeting->title,
                    scheduledAt: UserTime::format($meeting->scheduled_at, $actor->timezone),
                    durationMinutes: $meeting->duration_minutes,
                    agenda: $meeting->description,
                    joinUrl: $meeting->joinUrl(),
                    updatedByName: $actor->name,
                ));
            }

            $inAppRecipients = $this->preferences->filter($recipients, NotificationType::MEETING_UPDATED, NotificationChannel::IN_APP);

            Notification::send($inAppRecipients, new MeetingUpdatedNotification(
                projectName: $meeting->project->name,
                meetingTitle: $meeting->title,
                scheduledAt: UserTime::format($meeting->scheduled_at, $actor->timezone),
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
