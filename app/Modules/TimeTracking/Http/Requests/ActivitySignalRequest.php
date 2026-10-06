<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;

/**
 * One window of activity-pattern numbers from SprintSync Desktop: counts and
 * averages only (no keys, positions, apps or sites).
 */
final class ActivitySignalRequest extends TimeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('track', [TimeEntry::class, $this->workspace()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'window_start' => ['required', 'date', 'after:'.now()->subHours(TimerOperationRequest::MAX_DELAY_HOURS)->toIso8601String()],
            'window_end' => ['required', 'date', 'after:window_start', 'before_or_equal:'.now()->addMinutes(2)->toIso8601String()],
            'resets' => ['required', 'integer', 'between:0,100000'],
            'mean_gap_ms' => ['required', 'integer', 'between:0,86400000'],
            'variation_bp' => ['required', 'integer', 'between:0,1000000'],
        ];
    }
}
