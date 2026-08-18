<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http\Requests;

use App\Models\User;
use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

final class StoreMeetingRequest extends FormRequest
{
    public const MAX_PARTICIPANTS = 50;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [Meeting::class, $this->project()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'meeting_link' => ['nullable', 'url', 'max:2048'],
            'participant_user_ids' => ['nullable', 'array', 'max:'.self::MAX_PARTICIPANTS],
            'participant_user_ids.*' => ['integer', 'distinct'],
            'participant_emails' => ['nullable', 'array', 'max:'.self::MAX_PARTICIPANTS],
            'participant_emails.*' => ['email:rfc', 'max:255', 'distinct:ignore_case'],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            $userIds = (array) $this->input('participant_user_ids', []);

            if ($userIds === []) {
                return;
            }

            $project = $this->project();

            $assignable = User::query()
                ->whereIn('id', $userIds)
                ->get()
                ->filter(fn (User $user) => $project->workspace->userHasAtLeast($user, UserRole::ADMIN)
                    || $project->hasMember($user))
                ->pluck('id')
                ->all();

            foreach ($userIds as $index => $userId) {
                if (! in_array((int) $userId, $assignable, true)) {
                    $validator->errors()->add(
                        "participant_user_ids.{$index}",
                        'That person does not have access to this project.',
                    );
                }
            }
        });
    }

    public function project(): Project
    {
        return $this->route('project');
    }

    public function toDTO(): StoreMeetingData
    {
        $validated = $this->validated();

        return StoreMeetingData::from([
            ...$validated,
            'participant_user_ids' => array_map('intval', $validated['participant_user_ids'] ?? []),
            'participant_emails' => $validated['participant_emails'] ?? [],
        ]);
    }
}
