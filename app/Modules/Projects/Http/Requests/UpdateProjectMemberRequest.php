<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProjectMemberRequest extends FormRequest
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
            'role' => ['required', Rule::in(ProjectRole::values())],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            $member = $this->route('member');

            if (! $member instanceof User) {
                return;
            }

            if ($this->project()->workspace->isClient($member) && $this->input('role') !== ProjectRole::MEMBER->value) {
                $validator->errors()->add('role', 'Clients can only be a project member.');
            }
        });
    }

    public function project(): Project
    {
        return $this->route('project');
    }

    public function role(): ProjectRole
    {
        return ProjectRole::from($this->validated()['role']);
    }
}
