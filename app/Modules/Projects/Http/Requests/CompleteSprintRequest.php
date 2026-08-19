<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Data\SprintCarryOver;
use App\Modules\Projects\Models\Sprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CompleteSprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('complete', $this->sprint()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'carry_over' => ['nullable', Rule::in(SprintCarryOver::values())],
            'carry_over_sprint_id' => [
                'nullable',
                'integer',
                Rule::exists('sprints', 'id')->where('project_id', $this->sprint()->project_id),
            ],
        ];
    }

    public function sprint(): Sprint
    {
        return $this->route('sprint');
    }

    public function carryOver(): SprintCarryOver
    {
        return SprintCarryOver::tryFrom((string) $this->input('carry_over')) ?? SprintCarryOver::Backlog;
    }

    public function carryOverTarget(): ?Sprint
    {
        $id = $this->input('carry_over_sprint_id');

        return $id === null ? null : Sprint::query()->find($id);
    }
}
