<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Meetings\Actions\CreateMeetingAction;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\Support\Time\UserTime;
use App\UserRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ScheduleMeetingTool implements AssistantTool, ProvidesConfirmationDetails
{
    public function __construct(private readonly CreateMeetingAction $action) {}

    public function name(): string
    {
        return 'schedule_meeting';
    }

    public function description(): string
    {
        return 'Schedules a meeting inside a project and emails every participant an invitation. '
            .'Call list_projects first to get a real project_id — never guess one. '
            .'scheduled_at must be an absolute date and time in "YYYY-MM-DD HH:MM" form in the user timezone given in '
            .'this system message, resolved against the current date and time there; never invent a date. '
            .'Pass participant_emails for everyone who should be invited, including people outside the workspace. '
            .'Never invent an email address — ask the user for it. '
            .'If the project, title or date and time is missing, ask one question instead of guessing.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the project, obtained from list_projects.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Short meeting title.',
                    'minLength' => 2,
                    'maxLength' => 150,
                ],
                'scheduled_at' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Absolute start date and time as "YYYY-MM-DD HH:MM".',
                ],
                'duration_minutes' => [
                    'type' => 'integer',
                    'description' => 'Meeting length in minutes.',
                    'minimum' => 1,
                    'maximum' => 1440,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional agenda for the meeting.',
                    'maxLength' => 5000,
                ],
                'participant_emails' => [
                    'type' => 'array',
                    'description' => 'Email addresses of everyone to invite. Internal teammates and external guests both work.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['project_id', 'title', 'scheduled_at', 'duration_minutes'],
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
        $project = $context->workspace === null ? null : $this->resolveProject($context->workspace, $args, $context);
        $emails = $this->participantEmails($args);

        return array_filter([
            'project' => $project === null ? 'Unknown project' : UntrustedText::inline($project->name),
            'participants' => (string) count($emails),
        ]);
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

        $project = $this->resolveProject($workspace, $args, $context);

        if ($project === null) {
            return [
                'success' => false,
                'error_code' => 'project_not_found',
                'error' => 'That project does not exist or you do not have access to it. Use list_projects to see available projects.',
            ];
        }

        if (! $user->can('create', [Meeting::class, $project])) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to schedule meetings in {$project->name}.",
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

        $meeting = $this->action->handle($project, $user, StoreMeetingData::from([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'scheduled_at' => UserTime::toUtc($validated['scheduled_at'], $user->timezone)->toDateTimeString(),
            'duration_minutes' => $validated['duration_minutes'],
            'meeting_link' => null,
            'participant_user_ids' => [],
            'participant_emails' => $validated['participant_emails'] ?? [],
        ]));

        return [
            'success' => true,
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
            'message' => "Scheduled \"{$meeting->title}\" in {$project->name}. Invitations are on their way.",
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveProject(Workspace $workspace, array $args, ToolContext $context): ?Project
    {
        if (! isset($args['project_id'])) {
            return null;
        }

        return $workspace->accessibleProjectsFor($context->user)
            ->whereKey((int) $args['project_id'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<int, string>
     */
    private function participantEmails(array $args): array
    {
        $emails = $args['participant_emails'] ?? [];

        return is_array($emails) ? array_values(array_filter($emails, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $args): array
    {
        return Validator::make([
            'title' => $args['title'] ?? null,
            'description' => $args['description'] ?? null,
            'scheduled_at' => $args['scheduled_at'] ?? null,
            'duration_minutes' => $args['duration_minutes'] ?? null,
            'participant_emails' => $this->participantEmails($args),
        ], [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'participant_emails' => ['nullable', 'array', 'max:'.StoreMeetingData::MAX_PARTICIPANTS],
            'participant_emails.*' => ['email:rfc', 'max:255', 'distinct:ignore_case'],
        ])->validate();
    }
}
