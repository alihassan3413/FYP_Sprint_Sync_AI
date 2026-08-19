<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Requests;

use App\Modules\Workspace\Data\WorkspaceRoleData;
use App\Modules\Workspace\Data\WorkspaceRolePermissions;
use App\Modules\Workspace\Models\WorkspaceRole;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateWorkspaceRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->role()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['boolean'],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            $unknown = array_diff(
                array_keys((array) $this->input('permissions', [])),
                WorkspaceRolePermissions::values(),
            );

            foreach ($unknown as $key) {
                $validator->errors()->add('permissions', "Unknown permission: {$key}.");
            }
        });
    }

    public function role(): WorkspaceRole
    {
        return $this->route('role');
    }

    public function toDTO(): WorkspaceRoleData
    {
        return WorkspaceRoleData::from([
            'id' => $this->role()->id,
            'name' => (string) $this->input('name'),
            'slug' => $this->role()->slug,
            'permissions' => WorkspaceRolePermissions::normalise($this->input('permissions')),
        ]);
    }
}
