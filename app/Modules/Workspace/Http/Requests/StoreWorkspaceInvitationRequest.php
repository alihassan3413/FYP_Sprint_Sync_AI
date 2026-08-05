<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Http\Requests;

use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invite', $this->workspace()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(UserRole::invitationRoles())],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            $email = (string) $this->input('email');

            $alreadyMember = $this->workspace()
                ->users()
                ->where('users.email', $email)
                ->exists();

            if ($alreadyMember) {
                $validator->errors()->add('email', 'That person is already a member of this workspace.');
            }
        });
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function toDTO(): WorkspaceInvitationData
    {
        return WorkspaceInvitationData::from($this->validated());
    }
}
