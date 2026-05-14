<?php

namespace App\Modules\Workspace\Http\Requests;

use App\Modules\Workspace\Data\WorkspaceRoleData;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
        ];
    }

    public function toDTO(): WorkspaceRoleData
    {
        return WorkspaceRoleData::from($this->validated());
    }
}
