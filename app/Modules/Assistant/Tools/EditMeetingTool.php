<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Meetings\Actions\UpdateMeetingAction;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingParticipant;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Time\UserTime;
use App\UserRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EditMeetingTool implements AssistantTool, ProvidesConfirmationDetails
{
    private const EDITABLE_FIELDS = ['title', 'description', 'scheduled_at', 'duration_minutes', 'meeting_link'];

    public function __construct(private readonly UpdateMeetingAction $action) {}

    public function name(): string
    {
        return 'edit_meeting';
    }

    public function description(): string
    {
        return 'Changes an existing meeting and re-emails every participant, including external guests. '
            .'Call list_meetings first to get a real meeting_id — never guess one. '
            .'Only pass the fields the user asked to change; anything you omit keeps its current value. '
            .'scheduled_at must be an absolute date and time in "YYYY-MM-DD HH:MM" form in the user timezone given in '
            .'this system message, resolved against the current date and time there; never invent a date. '
            .'Passing participant_emails replaces the whole invite list, so include everyone who should stay invited, '
            .'not just the people being added. Never invent an email address — ask the user for it.';
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
                    'description' => 'The ID of the meeting to change, obtained from list_meetings.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'New meeting title. Omit to keep the current one.',
                    'minLength' => 2,
                    'maxLength' => 150,
                ],
                'scheduled_at' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'New absolute start date and time as "YYYY-MM-DD HH:MM". Omit to keep the current one.',
                ],
                'duration_minutes' => [
                    'type' => 'integer',
                    'description' => 'New meeting length in minutes. Omit to keep the current one.',
                    'minimum' => 1,
                    'maximum' => 1440,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'New agenda. Omit to keep the current one.',
                    'maxLength' => 5000,
                ],
                'meeting_link' => [
                    'type' => 'string',
                    'description' => 'New conferencing URL for the meeting. Omit to keep the current one.',
                    'maxLength' => 2048,
                ],
                'participant_emails' => [
                    'type' => 'array',
                    'description' => 'The complete list of email addresses that should be invited after this edit. Replaces the current list.',
                    'items' => ['type' => 'string'],
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

        try {
            $validated = $this->validate($args);
        } catch (ValidationException) {
            return [
                'project' => $this->label($meeting->project->name, 'Unknown project'),
                'meeting' => $this->label($meeting->title, 'Untitled meeting'),
            ];
        }

        $zone = $context->user->timezone;

        $details = [
            'project' => $this->label($meeting->project->name, 'Unknown project'),
            'meeting' => $this->label($meeting->title, 'Untitled meeting'),
        ];

        if (isset($validated['title']) && $validated['title'] !== $meeting->title) {
            $details['title'] = $this->label($meeting->title, 'Untitled meeting')
                .' → '.$this->label($validated['title'], 'Untitled meeting');
        }

        $scheduledAt = $this->resolveScheduledAt($meeting, $validated, $context->user);

        $details['when'] = $scheduledAt->equalTo($meeting->scheduled_at)
            ? UserTime::format($meeting->scheduled_at, $zone)
            : UserTime::format($meeting->scheduled_at, $zone).' → '.UserTime::format($scheduledAt, $zone);

        $duration = $validated['duration_minutes'] ?? $meeting->duration_minutes;

        $details['duration'] = $duration === $meeting->duration_minutes
            ? "{$meeting->duration_minutes} min"
            : "{$meeting->duration_minutes} min → {$duration} min";

        if (array_key_exists('description', $validated) && $validated['description'] !== $meeting->description) {
            $details['agenda'] = $validated['description'] === null
                ? 'cleared'
                : $this->label($validated['description'], 'updated');
        }

        if (array_key_exists('meeting_link', $validated) && $validated['meeting_link'] !== $meeting->meeting_link) {
            $details['link'] = $validated['meeting_link'] === null
                ? 'removed'
                : $this->label($validated['meeting_link'], 'updated');
        }

        $current = $this->currentParticipantEmails($meeting);

        if (array_key_exists('participant_emails', $validated)) {
            $next = $this->normalise($validated['participant_emails'] ?? []);

            $details['participants'] = $next === [] ? 'nobody' : implode(', ', $next);

            $added = array_values(array_diff($next, $current));
            $removed = array_values(array_diff($current, $next));

            if ($added !== []) {
                $details['adding'] = implode(', ', $added);
            }

            if ($removed !== []) {
                $details['removing'] = implode(', ', $removed);
            }
        } else {
            $details['notifies'] = $current === []
                ? 'nobody'
                : count($current).' existing participant'.(count($current) === 1 ? '' : 's');
        }

        if (! $this->hasChanges($meeting, $validated, $context->user)) {
            $details['changes'] = 'Nothing changes — nobody will be emailed.';
        }

        return $details;
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

        if (! $user->can('update', $meeting)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to edit meetings in {$meeting->project->name}.",
            ];
        }

        try {
            $validated = $this->validate($args);
        } catch (ValidationException $e) {
            return [
                'success' => false,
                'error_code' => 'invalid_arguments',
                'error' => 'The meeting details were invalid: '.implode(' ', $e->validator->errors()->all()),
            ];
        }

        if ($validated === []) {
            return [
                'success' => false,
                'error_code' => 'nothing_to_change',
                'error' => 'No changes were supplied. Ask the user what they want to change about the meeting.',
            ];
        }

        $changed = $this->hasChanges($meeting, $validated, $user);
        $project = $meeting->project;

        $meeting = $this->action->handle($meeting, $user, StoreMeetingData::from([
            'title' => $validated['title'] ?? $meeting->title,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $meeting->description,
            'scheduled_at' => $this->resolveScheduledAt($meeting, $validated, $user)->toDateTimeString(),
            'duration_minutes' => $validated['duration_minutes'] ?? $meeting->duration_minutes,
            'meeting_link' => array_key_exists('meeting_link', $validated) ? $validated['meeting_link'] : $meeting->meeting_link,
            'participant_user_ids' => [],
            'participant_emails' => array_key_exists('participant_emails', $validated)
                ? $this->normalise($validated['participant_emails'] ?? [])
                : $this->currentParticipantEmails($meeting),
        ]));

        return [
            'success' => true,
            'changed' => $changed,
            'meeting' => [
                'id' => $meeting->id,
                'title' => $meeting->title,
                'project_id' => $project->id,
                'project_name' => $project->name,
                'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
                'duration_minutes' => $meeting->duration_minutes,
                'participant_count' => $meeting->participants()->count(),
            ],
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $project->id,
            ])."?meeting={$meeting->id}",
            'message' => $changed
                ? "Updated \"{$meeting->title}\" in {$project->name}. Participants have been notified."
                : "\"{$meeting->title}\" already matched those details, so nothing changed and nobody was emailed.",
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
     * @param  array<string, mixed>  $validated
     */
    private function resolveScheduledAt(Meeting $meeting, array $validated, User $user): Carbon
    {
        return isset($validated['scheduled_at'])
            ? UserTime::toUtc($validated['scheduled_at'], $user->timezone)
            : $meeting->scheduled_at->copy();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function hasChanges(Meeting $meeting, array $validated, User $user): bool
    {
        if (isset($validated['title']) && $validated['title'] !== $meeting->title) {
            return true;
        }

        if (array_key_exists('description', $validated) && $validated['description'] !== $meeting->description) {
            return true;
        }

        if (! $this->resolveScheduledAt($meeting, $validated, $user)->equalTo($meeting->scheduled_at)) {
            return true;
        }

        if (isset($validated['duration_minutes']) && $validated['duration_minutes'] !== $meeting->duration_minutes) {
            return true;
        }

        if (array_key_exists('meeting_link', $validated) && $validated['meeting_link'] !== $meeting->meeting_link) {
            return true;
        }

        if (array_key_exists('participant_emails', $validated)) {
            return $this->normalise($validated['participant_emails'] ?? []) !== $this->currentParticipantEmails($meeting);
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function currentParticipantEmails(Meeting $meeting): array
    {
        return $this->normalise($meeting->participants->pluck('email')->all());
    }

    /**
     * @param  array<int, mixed>  $emails
     * @return array<int, string>
     */
    private function normalise(array $emails): array
    {
        $normalised = [];

        foreach ($emails as $email) {
            if (! is_string($email)) {
                continue;
            }

            $value = MeetingParticipant::normaliseEmail($email);

            if ($value !== '' && ! in_array($value, $normalised, true)) {
                $normalised[] = $value;
            }
        }

        sort($normalised);

        return $normalised;
    }

    private function label(?string $value, string $fallback): string
    {
        return UntrustedText::inline($value) ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $args): array
    {
        $payload = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (array_key_exists($field, $args)) {
                $payload[$field] = $args[$field];
            }
        }

        if (array_key_exists('participant_emails', $args)) {
            $emails = $args['participant_emails'];
            $payload['participant_emails'] = is_array($emails)
                ? array_values(array_filter($emails, 'is_string'))
                : $emails;
        }

        return Validator::make($payload, [
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'scheduled_at' => ['sometimes', 'required', 'date'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'meeting_link' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'participant_emails' => ['sometimes', 'nullable', 'array', 'max:'.StoreMeetingData::MAX_PARTICIPANTS],
            'participant_emails.*' => ['email:rfc', 'max:255', 'distinct:ignore_case'],
        ])->validate();
    }
}
