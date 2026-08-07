<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->task()) ?? false;
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
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            $assignedTo = $this->input('assigned_to');

            if ($assignedTo !== null && ! $this->task()->workspace->users()->whereKey($assignedTo)->exists()) {
                $validator->errors()->add('assigned_to', 'The assignee must be a member of this workspace.');
            }
        });
    }

    public function task(): Task
    {
        return $this->route('task');
    }

    public function toDTO(): StoreTaskData
    {
        return StoreTaskData::from($this->validated());
    }
}
