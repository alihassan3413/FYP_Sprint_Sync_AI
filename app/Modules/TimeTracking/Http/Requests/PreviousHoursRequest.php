<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Support\HoursInput;

/**
 * "Add previous hours": a period, a project, a reason and one total per
 * person. Empty rows are ignored; every filled row must be valid.
 */
final class PreviousHoursRequest extends TimeRequest
{
    public const MAX_ROWS = 100;

    public function authorize(): bool
    {
        return $this->user()->can('manageTeam', [TimeEntry::class, $this->workspace()]);
    }

    protected function prepareForValidation(): void
    {
        $rows = collect((array) $this->input('rows', []))
            ->filter(fn ($row) => is_array($row) && filled($row['hours'] ?? null))
            ->values()
            ->all();

        $this->merge(['rows' => $rows]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->projectRules(),
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'reason' => ['required', 'string', 'max:500'],
            'billable' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.person' => ['required', 'string', 'distinct', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! $this->workspace()->people()->where('public_id', $value)->exists()) {
                    $fail('Choose people on your team.');
                }
            }],
            'rows.*.hours' => ['required', $this->hoursRule()],
            'idempotency_key' => self::KEY_RULES,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'Enter hours for at least one person.',
            'period_end.after_or_equal' => 'The period must end on or after the day it starts.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['rows.*.hours' => 'hours'];
    }

    /**
     * @return list<array{person: Person, seconds: int}>
     */
    public function rows(): array
    {
        $people = $this->workspace()->people()->whereIn('public_id', array_column($this->validated('rows'), 'person'))->get()->keyBy('public_id');

        return array_map(function (array $row) use ($people) {
            return ['person' => $people->get($row['person']), 'seconds' => (int) HoursInput::toSeconds((string) $row['hours'])];
        }, $this->validated('rows'));
    }
}
