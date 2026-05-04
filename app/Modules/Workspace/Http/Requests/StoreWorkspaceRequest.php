<?php

namespace App\Modules\Workspace\Http\Requests;

use App\Modules\Workspace\Data\WorkspaceData;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:workspaces,slug'],
        ];
    }

    public function toDTO(): WorkspaceData
    {
        return WorkspaceData::from($this->validated());
    }
}
