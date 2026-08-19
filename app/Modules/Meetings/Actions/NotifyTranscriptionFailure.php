<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Models\User;
use App\Modules\Meetings\Models\MeetingTranscript;
use App\Notifications\TranscriptionFailedNotification;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class NotifyTranscriptionFailure
{
    public function handle(MeetingTranscript $transcript): void
    {
        $recipients = $this->recipients($transcript);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new TranscriptionFailedNotification(
                meetingTitle: $transcript->meeting->title,
                projectName: $transcript->project->name,
                reason: (string) $transcript->failure_reason,
                url: route('workspace.projects.show', [
                    'workspace' => $transcript->workspace->slug,
                    'project' => $transcript->project_id,
                ])."?meeting={$transcript->meeting_id}&transcript=upload",
            ));
        } catch (Throwable $e) {
            Log::error('Transcription failure notification dispatch failed', [
                'transcript_id' => $transcript->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * The report calls this role Scrum Master. SprintSync expresses it as
     * project managers plus workspace admins, per the FR27 mapping.
     *
     * @return Collection<int, User>
     */
    private function recipients(MeetingTranscript $transcript): Collection
    {
        $project = $transcript->project;

        $managers = $project->members()
            ->wherePivot('role', ProjectRole::MANAGER->value)
            ->get();

        $admins = $project->workspace->users()
            ->wherePivotIn('role', [UserRole::OWNER->value, UserRole::ADMIN->value])
            ->get();

        return $managers->concat($admins)->unique('id')->values();
    }
}
