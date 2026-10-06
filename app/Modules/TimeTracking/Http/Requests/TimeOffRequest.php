<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\LeaveType;
use App\Modules\TimeTracking\Models\TimeEntry;
use Illuminate\Validation\Rule;

/** A holiday (kind = holiday) or leave for one person (kind = leave). */
final class TimeOffRequest extends TimeRequest
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
        $holiday = $this->input('kind') === 'holiday';

        return [
            'kind' => ['required', Rule::in(['holiday', 'leave'])],
            'name' => [$holiday ? 'required' : 'nullable', 'string', 'max:120'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => [$holiday ? 'nullable' : 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'hours' => ['required', 'string', $this->hoursRule()],
            'people' => ['nullable', 'array'],
            'people.*' => ['string', 'distinct'],
            'person' => [$holiday ? 'nullable' : 'required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && $this->targetPerson() === null) {
                    $fail('Choose someone on your team.');
                }
            }],
            'type' => [$holiday ? 'nullable' : 'required', Rule::enum(LeaveType::class)],
            'note' => ['nullable', 'string', 'max:300'],
            'idempotency_key' => self::KEY_RULES,
        ];
    }

    /**
     * @return list<Person>
     */
    public function people(): array
    {
        $ids = (array) $this->validated('people', []);

        return $ids === [] ? [] : $this->workspace()->people()->whereIn('public_id', $ids)->get()->all();
    }
}
