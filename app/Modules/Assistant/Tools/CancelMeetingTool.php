<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Meetings\Actions\DeleteMeetingAction;
use App\Modules\Meetings\Actions\ResolveMeetingRecipients;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingParticipant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Time\UserTime;
use App\UserRole;

final class CancelMeetingTool implements AssistantTool, ProvidesConfirmationDetails
{
    public function __construct(
        private readonly DeleteMeetingAction $action,
        private readonly ResolveMeetingRecipients $recipients,
    ) {}

    public function name(): string
    {
        return 'cancel_meeting';
    }

    public function description(): string
    {
        return 'Cancels a meeting, deletes it for everyone and emails every participant a cancellation, '
            .'including external guests. This cannot be undone. '
            .'Call list_meetings first to get a real meeting_id — never guess one. '
            .'If the user wants to move a meeting rather than call it off, use edit_meeting instead. '
            .'If it is unclear which meeting the user means, ask before calling this.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the meeting to cancel, obtained from list_meetings.',
                ],
            ],
            'required' => ['meeting_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function authorize(ToolContext $context): bool
    {
        $workspace = $context->workspace;

        if ($workspace === null) {
            return false;
        }

        if ($workspace->userHasAtLeast($context->user, UserRole::ADMIN)) {
            return true;
        }

        return $workspace->managedProjectsFor($context->user)->exists();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    public function confirmationDetails(array $args, ToolContext $context): array
    {
        $meeting = $context->workspace === null
            ? null
            : $this->resolveMeeting($context->workspace, $args, $context->user);

        if ($meeting === null) {
            return ['meeting' => 'Unknown meeting'];
        }

        $emails = $this->notifiedEmails($meeting, $context->user);

        return [
            'project' => $this->label($meeting->project->name, 'Unknown project'),
            'meeting' => $this->label($meeting->title, 'Untitled meeting'),
            'when' => UserTime::format($meeting->scheduled_at, $context->user->timezone),
            'duration' => "{$meeting->duration_minutes} min",
            'emailing' => $emails === [] ? 'nobody else is on this meeting' : implode(', ', $emails),
            'warning' => 'Cancelling deletes the meeting for everyone. This cannot be undone.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;
        $user = $context->user;

        if ($workspace === null) {
            return ['success' => false, 'error_code' => 'no_workspace', 'error' => 'No active workspace is selected.'];
        }

        $meeting = $this->resolveMeeting($workspace, $args, $user);

        if ($meeting === null) {
            return [
                'success' => false,
                'error_code' => 'meeting_not_found',
                'error' => 'That meeting does not exist or you do not have access to it. Use list_meetings to see available meetings.',
            ];
        }

        if (! $user->can('delete', $meeting)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to cancel meetings in {$meeting->project->name}.",
            ];
        }

        $project = $meeting->project;

        $cancelled = [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
            'duration_minutes' => $meeting->duration_minutes,
            'notified_count' => count($this->notifiedEmails($meeting, $user)),
        ];

        $this->action->handle($meeting, $user);

        return [
            'success' => true,
            'meeting' => $cancelled,
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $project->id,
            ]),
            'message' => "Cancelled \"{$cancelled['title']}\" in {$project->name}. Everyone on the meeting has been emailed.",
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveMeeting(Workspace $workspace, array $args, User $user): ?Meeting
    {
        if (! isset($args['meeting_id'])) {
            return null;
        }

        return Meeting::query()
            ->with(['project', 'participants'])
            ->whereKey((int) $args['meeting_id'])
            ->whereIn('project_id', $workspace->accessibleProjectsFor($user)->select('projects.id'))
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function notifiedEmails(Meeting $meeting, User $actor): array
    {
        $internal = $this->recipients->handle($meeting, $actor)
            ->map(fn (User $recipient) => MeetingParticipant::normaliseEmail($recipient->email));

        $external = $this->recipients->externals($meeting)
            ->map(fn (MeetingParticipant $participant) => $participant->email);

        return $internal->merge($external)->unique()->sort()->values()->all();
    }

    private function label(?string $value, string $fallback): string
    {
        return UntrustedText::inline($value) ?? $fallback;
    }
}
