<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Requests;

use App\Modules\Workspace\Data\WorkspaceData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Rules\WorkspaceSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->workspace()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'slug' => [
                'required',
                'string',
                'max:60',
                Rule::unique('workspaces', 'slug')->ignore($this->workspace()->id),
                new WorkspaceSlug,
            ],
        ];
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function toDTO(): WorkspaceData
    {
        return WorkspaceData::from([
            ...$this->validated(),
            'settings' => $this->workspace()->settings ?? [],
            'is_active' => $this->workspace()->is_active,
        ]);
    }
}
