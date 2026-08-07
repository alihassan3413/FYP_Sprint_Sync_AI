<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Data\StoreMeetingData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

final class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Meeting::class, $this->project()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'meeting_link' => ['nullable', 'url', 'max:2048'],
        ];
    }

    public function project(): Project
    {
        return $this->route('project');
    }

    public function toDTO(): StoreMeetingData
    {
        return StoreMeetingData::from($this->validated());
    }
}
