<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReorderBoardColumnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reorder', [BoardColumn::class, $this->project()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $columnIds = $this->project()->boardColumns()->pluck('id');

        return [
            'column_ids' => ['required', 'array', 'size:'.$columnIds->count()],
            'column_ids.*' => ['integer', 'distinct', Rule::in($columnIds)],
        ];
    }

    public function project(): Project
    {
        return $this->route('project');
    }

    /**
     * @return array<int, int>
     */
    public function orderedColumnIds(): array
    {
        return $this->validated('column_ids');
    }
}
