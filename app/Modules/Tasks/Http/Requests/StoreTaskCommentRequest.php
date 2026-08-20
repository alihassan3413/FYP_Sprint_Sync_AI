<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Tasks\Data\TaskCommentData;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTaskCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [TaskComment::class, $this->task()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => TaskCommentData::bodyRules(),
        ];
    }

    public function task(): Task
    {
        return $this->route('task');
    }
}
