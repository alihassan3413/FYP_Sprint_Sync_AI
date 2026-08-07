<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Data\StoreProjectData;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Project::class, $this->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function toDTO(): StoreProjectData
    {
        return StoreProjectData::from($this->validated());
    }
}
