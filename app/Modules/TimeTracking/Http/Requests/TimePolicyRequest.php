<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Data\OvertimeMode;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Support\HoursInput;
use Illuminate\Validation\Rule;

/** The owner's time rules. Minutes and hours are whole numbers or hours with up to 2 decimals. */
final class TimePolicyRequest extends TimeRequest
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
            'idle_enabled' => ['required', 'boolean'],
            'idle_minutes' => ['nullable', 'required_if:idle_enabled,true', 'integer', 'between:2,120'],
            'weekly_target_hours' => ['nullable', 'string', $this->hoursRule()],
            'lunch_enabled' => ['required', 'boolean'],
            'lunch_minutes' => ['nullable', 'required_if:lunch_enabled,true', 'integer', 'between:5,180'],
            'overtime_mode' => ['required', Rule::enum(OvertimeMode::class)],
            'integrity_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idle_minutes.required_if' => 'Choose after how many minutes to pause.',
            'idle_minutes.between' => 'Use between 2 and 120 minutes.',
            'lunch_minutes.required_if' => 'Choose how long the paid lunch is.',
            'lunch_minutes.between' => 'Use between 5 and 180 minutes.',
        ];
    }

    /**
     * @return array{idle_enabled: bool, idle_seconds: int|null, weekly_target_seconds: int|null, lunch_enabled: bool, lunch_allowance_seconds: int|null, overtime_mode: OvertimeMode, integrity_enabled: bool}
     */
    public function policy(): array
    {
        $target = $this->validated('weekly_target_hours');
        $targetSeconds = filled($target) ? HoursInput::toSeconds((string) $target) : null;

        return [
            'idle_enabled' => $this->boolean('idle_enabled'),
            'idle_seconds' => $this->boolean('idle_enabled') ? (int) $this->validated('idle_minutes') * 60 : null,
            'weekly_target_seconds' => $targetSeconds ?: null,
            'lunch_enabled' => $this->boolean('lunch_enabled'),
            'lunch_allowance_seconds' => $this->boolean('lunch_enabled') ? (int) $this->validated('lunch_minutes') * 60 : null,
            'overtime_mode' => OvertimeMode::from((string) $this->validated('overtime_mode')),
            'integrity_enabled' => $this->boolean('integrity_enabled'),
        ];
    }
}
