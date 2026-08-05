<?php

declare(strict_types=1);

namespace App\Modules\Teams\Http\Requests;

use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->workspace()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(UserRole::invitationRoles())],
            'workspace_role_id' => [
                'nullable',
                Rule::exists('workspace_roles', 'id')->where('workspace_id', $this->workspace()->id),
            ],
        ];
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function role(): UserRole
    {
        return UserRole::from((string) $this->input('role'));
    }

    public function customRole(): ?WorkspaceRole
    {
        $id = $this->input('workspace_role_id');

        return $id === null ? null : WorkspaceRole::query()->find($id);
    }
}
