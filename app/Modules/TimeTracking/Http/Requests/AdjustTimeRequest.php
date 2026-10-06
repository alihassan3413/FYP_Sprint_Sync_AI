<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Support\HoursInput;

/** The owner's correction to one entry: signed hours plus a reason. */
final class AdjustTimeRequest extends TimeRequest
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
            'hours' => ['required', $this->hoursRule(allowNegative: true)],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => self::KEY_RULES,
        ];
    }

    public function seconds(): int
    {
        return (int) HoursInput::toSeconds((string) $this->validated('hours'), allowNegative: true);
    }
}
