<?php

namespace App\Modules\Workspace\Http\Requests;

use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;

        // Later you can restrict:
        // return $this->user()->can('invite-members');
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(UserRole::invitationRoles())],
        ];
    }

    public function toDTO(): WorkspaceInvitationData
    {
        return WorkspaceInvitationData::from($this->validated());
    }
}
