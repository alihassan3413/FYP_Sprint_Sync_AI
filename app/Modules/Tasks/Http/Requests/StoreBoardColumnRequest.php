<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Requests;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBoardColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [BoardColumn::class, $this->project()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:50'],
        ];
    }

    public function project(): Project
    {
        return $this->route('project');
    }
}
