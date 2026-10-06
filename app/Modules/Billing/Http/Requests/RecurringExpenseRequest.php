<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\Currency;
use App\Modules\Expenses\Data\ExpenseCategory;
use Carbon\CarbonImmutable;

/**
 * A monthly expense. Days 29–31 are not offered so every month has the day.
 */
final class RecurringExpenseRequest extends ExpenseDetailsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'day_of_month' => ['required', 'integer', 'between:1,28'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['day_of_month.between' => 'Pick a day from 1 to 28 so every month has it.'];
    }

    /**
     * @return array{category: ExpenseCategory, description: string, vendor: ?string, amount_minor: int, currency: Currency, day_of_month: int, starts_on: CarbonImmutable, ends_on: ?CarbonImmutable, client_id: ?int, project_id: ?int, notes: ?string}
     */
    public function recurring(): array
    {
        return [...$this->details(), 'day_of_month' => (int) $this->validated('day_of_month'), 'starts_on' => $this->dateField('starts_on'), 'ends_on' => $this->dateField('ends_on')];
    }
}
