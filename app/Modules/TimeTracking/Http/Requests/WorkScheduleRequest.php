<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;

/** An expected schedule for the workspace or one person. No days = no schedule. */
final class WorkScheduleRequest extends TimeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageTeam', [TimeEntry::class, $this->workspace()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && $this->targetPerson() === null) {
                    $fail('Choose someone on your team.');
                }
            }],
            'days' => ['present', 'array'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => ['nullable', 'required_with:days.0', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:days.0', 'date_format:H:i', 'different:start_time'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
