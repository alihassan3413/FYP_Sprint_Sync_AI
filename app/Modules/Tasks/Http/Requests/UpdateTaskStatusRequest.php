<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Data\UpdateTaskStatusData;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', $this->task()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'board_column_id' => [
                'required',
                'integer',
                Rule::exists('board_columns', 'id')->where('project_id', $this->task()->project_id),
            ],
        ];
    }

    public function task(): Task
    {
        return $this->route('task');
    }

    public function toDTO(): UpdateTaskStatusData
    {
        return UpdateTaskStatusData::from($this->validated());
    }
}
