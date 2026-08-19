<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Requests;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Task::class, $this->project()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'sprint_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            $sprintId = $this->input('sprint_id');

            if ($sprintId !== null && ! Sprint::query()
                ->whereKey((int) $sprintId)
                ->where('project_id', $this->project()->id)
                ->exists()) {
                $validator->errors()->add('sprint_id', 'That sprint does not belong to this project.');
            }

            $assignedTo = $this->input('assigned_to');

            if ($assignedTo === null) {
                return;
            }

            $assignee = User::find($assignedTo);
            $project = $this->project();

            $isAssignable = $assignee !== null
                && ! $project->workspace->isClient($assignee)
                && ($project->workspace->userHasAtLeast($assignee, UserRole::ADMIN) || $project->hasMember($assignee));

            if (! $isAssignable) {
                $validator->errors()->add('assigned_to', 'The assignee must be a member of this project.');
            }
        });

        $validator->after(function ($validator) {
            if (! $this->project()->workspace->isClient($this->user())) {
                return;
            }

            if ($this->input('assigned_to') !== null) {
                $validator->errors()->add('assigned_to', 'Clients cannot assign the tasks they request.');
            }

            if ($this->input('sprint_id') !== null) {
                $validator->errors()->add('sprint_id', 'Clients cannot put a task into a sprint.');
            }
        });
    }

    public function project(): Project
    {
        return $this->route('project');
    }

    public function toDTO(): StoreTaskData
    {
        return StoreTaskData::from($this->validated());
    }
}
