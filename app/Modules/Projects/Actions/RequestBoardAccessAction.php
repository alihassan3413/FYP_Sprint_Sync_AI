<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectAccessRequest;
use App\Notifications\BoardAccessRequestedNotification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use App\UserRole;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * One request per person per board. Asking again while it is pending changes
 * nothing and notifies no one; asking again after a decline reopens it.
 */
final class RequestBoardAccessAction
{
    public function __construct(private readonly NotificationPreferenceGate $preferences) {}

    public function handle(Project $project, User $user): ProjectAccessRequest
    {
        $request = ProjectAccessRequest::query()->firstOrNew(['project_id' => $project->id, 'user_id' => $user->id]);

        if ($request->exists && $request->status !== 'declined') {
            return $request;
        }

        $request->forceFill(['status' => 'pending', 'decided_by' => null, 'decided_at' => null])->save();

        $this->notifyManagers($project, $user);

        return $request;
    }

    private function notifyManagers(Project $project, User $requester): void
    {
        try {
            $recipients = $project->workspace->users()
                ->wherePivotIn('role', [UserRole::OWNER->value, UserRole::ADMIN->value])
                ->get()
                ->merge($project->managers()->get())
                ->unique('id')
                ->reject(fn (User $user) => $user->is($requester))
                ->values();

            $recipients = $this->preferences->filter($recipients, NotificationType::BOARD_ACCESS_REQUESTED, NotificationChannel::IN_APP);

            Notification::send($recipients, new BoardAccessRequestedNotification(
                projectName: $project->name,
                requesterName: $requester->name,
                url: route('workspace.projects.show', ['workspace' => $project->workspace->slug, 'project' => $project->id, 'share' => 1]),
            ));
        } catch (Throwable $e) {
            Log::error('Board access request notification failed', ['project_id' => $project->id, 'exception' => $e]);
        }
    }
}
