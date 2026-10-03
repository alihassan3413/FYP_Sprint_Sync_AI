<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\DecimalAmount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Draft corrections, keyed by line position: { "0": "160", "1": "" }.
 *
 * Hourly invoices accept hours only (up to 2 decimals; blank = not entered,
 * 0 = nothing to bill). Fixed invoices accept amounts only. Nothing else on
 * the line (rates, names, totals) can be changed through this request: other
 * keys are simply never read.
 */
final class UpdateInvoiceLinesRequest extends FormRequest
{
    /** A month has at most 744 hours; anything above is a typo. */
    public const MAX_HOURS = 744;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->invoice()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array'],
            'lines.*' => [$this->isHourly() ? 'nullable' : 'required', 'string', $this->valueRule()],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $positions = $this->invoice()->lines()->pluck('position')->all();
            $unknown = array_diff(array_map('intval', array_keys((array) $this->input('lines', []))), $positions);

            if ($unknown !== []) {
                $validator->errors()->add('lines', 'Some lines are not on this invoice.');
            }
        }];
    }

    public function invoice(): Invoice
    {
        return $this->route('invoice');
    }

    /**
     * @return array<int, int|null>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->validated('lines') as $position => $value) {
            $values[(int) $position] = $value === null ? null : DecimalAmount::scaled($value, 2);
        }

        return $values;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $this->merge(['lines' => array_map(
            fn ($value) => is_int($value) ? (string) $value : (is_string($value) ? (trim($value) === '' ? null : trim(str_replace(',', '', $value))) : $value),
            $lines,
        )]);
    }

    private function isHourly(): bool
    {
        return $this->invoice()->pricing_mode === PricingMode::Hourly;
    }

    private function valueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($this->isHourly()) {
                if (! is_string($value) || ! DecimalAmount::isValid($value, 2, 3) || DecimalAmount::scaled($value, 2) > self::MAX_HOURS * 100) {
                    $fail('Enter hours like 160 or 160.5 (up to '.self::MAX_HOURS.').');
                }

                return;
            }

            if (! is_string($value) || ! DecimalAmount::isValid($value, 2, 9)) {
                $fail('Enter an amount like 1200 or 1200.50.');
            }
        };
    }
}
