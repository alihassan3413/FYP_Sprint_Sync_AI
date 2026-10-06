<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Support\HoursInput;

/**
 * Time for one day. People add their own; the owner may add it for anyone
 * on the team (person).
 */
final class ManualTimeRequest extends TimeRequest
{
    public function authorize(): bool
    {
        if ($this->filled('person')) {
            return $this->user()->can('manageTeam', [TimeEntry::class, $this->workspace()]);
        }

        return $this->user()->can('track', [TimeEntry::class, $this->workspace()]);
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
            ...$this->projectRules(),
            'date' => ['required', 'date_format:Y-m-d'],
            'hours' => ['required', $this->hoursRule()],
            'billable' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => self::KEY_RULES,
        ];
    }

    public function seconds(): int
    {
        return (int) HoursInput::toSeconds((string) $this->validated('hours'));
    }
}
