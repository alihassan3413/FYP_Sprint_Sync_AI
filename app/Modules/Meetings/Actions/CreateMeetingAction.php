<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Mail\MeetingScheduledMail;
use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Notifications\MeetingScheduledNotification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

final class CreateMeetingAction
{
    public function __construct(
        private readonly ResolveMeetingRecipients $resolveMeetingRecipients,
        private readonly NotificationPreferenceGate $preferences,
        private readonly RecordAuditLogAction $auditLogger,
        private readonly SyncMeetingParticipants $syncParticipants,
    ) {}

    public function handle(Project $project, User $creator, StoreMeetingData $data): Meeting
    {
        $meeting = DB::transaction(function () use ($project, $creator, $data) {
            $meeting = $project->meetings()->create([
                'title' => $data->title,
                'description' => $data->description,
                'scheduled_at' => $data->scheduled_at,
                'duration_minutes' => $data->duration_minutes,
                'meeting_link' => $data->meeting_link,
                'join_token' => Str::random(64),
                'workspace_id' => $project->workspace_id,
                'created_by' => $creator->id,
            ]);

            $this->syncParticipants->handle(
                $meeting,
                $project,
                $data->participant_user_ids,
                $data->participant_emails,
            );

            return $meeting;
        });

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $creator,
            AuditAction::MEETING_SCHEDULED,
            "{$creator->name} scheduled \"{$meeting->title}\".",
            $meeting,
        );

        $this->notify($meeting, $creator);

        return $meeting;
    }

    private function notify(Meeting $meeting, User $actor): void
    {
        $recipients = $this->resolveMeetingRecipients->handle($meeting, $actor);

        if ($recipients->isEmpty() && $this->resolveMeetingRecipients->externals($meeting)->isEmpty()) {
            return;
        }

        try {
            $emailRecipients = $this->preferences->filter($recipients, NotificationType::MEETING_SCHEDULED, NotificationChannel::EMAIL);

            foreach ($emailRecipients as $recipient) {
                Mail::to($recipient->email)->queue(new MeetingScheduledMail(
                    projectName: $meeting->project->name,
                    meetingTitle: $meeting->title,
                    scheduledAt: $meeting->scheduled_at->format('F j, Y g:i A'),
                    durationMinutes: $meeting->duration_minutes,
                    agenda: $meeting->description,
                    joinUrl: $meeting->joinUrl(),
                    scheduledByName: $actor->name,
                ));
            }

            foreach ($this->resolveMeetingRecipients->externals($meeting) as $external) {
                Mail::to($external->email)->queue(new MeetingScheduledMail(
                    projectName: $meeting->project->name,
                    meetingTitle: $meeting->title,
                    scheduledAt: $meeting->scheduled_at->format('F j, Y g:i A'),
                    durationMinutes: $meeting->duration_minutes,
                    agenda: $meeting->description,
                    joinUrl: $meeting->joinUrl(),
                    scheduledByName: $actor->name,
                ));
            }

            $inAppRecipients = $this->preferences->filter($recipients, NotificationType::MEETING_SCHEDULED, NotificationChannel::IN_APP);

            Notification::send($inAppRecipients, new MeetingScheduledNotification(
                projectName: $meeting->project->name,
                meetingTitle: $meeting->title,
                scheduledAt: $meeting->scheduled_at->format('F j, Y g:i A'),
                scheduledByName: $actor->name,
                url: route('workspace.projects.show', ['workspace' => $meeting->project->workspace->slug, 'project' => $meeting->project_id]),
            ));
        } catch (Throwable $e) {
            Log::error('Meeting scheduled notification dispatch failed', [
                'meeting_id' => $meeting->id,
                'exception' => $e,
            ]);
        }
    }
}
