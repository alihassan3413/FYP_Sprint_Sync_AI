<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

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

    public function project(): Project
    {
        return $this->route('project');
    }

    public function role(): ProjectRole
    {
        return ProjectRole::from($this->validated()['role']);
    }
}
