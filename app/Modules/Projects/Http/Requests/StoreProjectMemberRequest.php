<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->project()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('workspace_users', 'user_id')->where('workspace_id', $this->project()->workspace_id),
                Rule::unique('project_users', 'user_id')->where('project_id', $this->project()->id),
            ],
            'role' => ['required', Rule::in(ProjectRole::values())],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            $member = User::find($this->input('user_id'));

            if ($member === null) {
                return;
            }

            if ($this->project()->workspace->isClient($member) && $this->input('role') !== ProjectRole::MEMBER->value) {
                $validator->errors()->add('role', 'Clients can only be added to a project as a member.');
            }
        });
    }

    public function project(): Project
    {
        return $this->route('project');
    }

    public function member(): User
    {
        return User::query()->findOrFail($this->validated()['user_id']);
    }

    public function role(): ProjectRole
    {
        return ProjectRole::from($this->validated()['role']);
    }
}
