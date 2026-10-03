<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Models\Person;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Needs both halves of the boundary: managing the person's profile and the
 * existing permission to invite people into the workspace.
 */
final class GivePersonAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('update', $this->person())
            && $user->can('invite', $this->workspace());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::in([UserRole::MEMBER->value, UserRole::ADMIN->value])],
            'workspace_role_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_roles', 'id')->where('workspace_id', $this->workspace()->id),
            ],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $person = $this->person();

            if ($person->user_id !== null) {
                $validator->errors()->add('email', "{$person->name} already has SprintSync access.");

                return;
            }

            $member = $this->workspace()->users()->where('users.email', $this->string('email')->toString())->first();

            if ($member !== null && $this->workspace()->people()->where('user_id', $member->id)->exists()) {
                $validator->errors()->add('email', "{$member->name} already has SprintSync access as another team member.");
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'role' => $this->input('role') ?: UserRole::MEMBER->value,
            'workspace_role_id' => $this->input('workspace_role_id') ?: null,
        ]);
    }

    public function person(): Person
    {
        return $this->route('person');
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function toInvitation(): WorkspaceInvitationData
    {
        return WorkspaceInvitationData::from($this->validated());
    }
}
