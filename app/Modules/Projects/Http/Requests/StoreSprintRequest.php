<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Data\StoreSprintData;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use Illuminate\Foundation\Http\FormRequest;

class StoreSprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Sprint::class, $this->project()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'goal' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }

    public function withValidator(mixed $validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->overlappingSprintExists()) {
                $validator->errors()->add('starts_on', 'Another sprint in this project already covers those dates.');
            }
        });
    }

    public function project(): Project
    {
        return $this->route('project');
    }

    protected function overlappingSprintExists(): bool
    {
        return $this->overlapQuery()->exists();
    }

    protected function overlapQuery()
    {
        return Sprint::query()
            ->where('project_id', $this->project()->id)
            ->whereDate('starts_on', '<=', $this->date('ends_on'))
            ->whereDate('ends_on', '>=', $this->date('starts_on'));
    }

    public function toDTO(): StoreSprintData
    {
        return StoreSprintData::from($this->validated());
    }
}
