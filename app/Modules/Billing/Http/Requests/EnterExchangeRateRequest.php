<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\Currency;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 1 {base} = {rate} {quote}. The rate is kept as the exact decimal typed
 * (up to 12 decimal places), never a float.
 */
final class EnterExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return $this->user() !== null && $workspace->allowsFinance($this->user(), FinancePermission::Manage);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_currency' => ['required', Rule::enum(Currency::class)],
            'quote_currency' => ['required', Rule::enum(Currency::class), 'different:base_currency'],
            'rate' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,12})?$/', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && preg_match('/^\d{1,12}(\.\d{1,12})?$/', $value) === 1 && BigDecimal::of($value)->isZero()) {
                    $fail('The rate must be more than zero.');
                }
            }],
            'rate_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate.regex' => 'Enter the rate as a plain number, like 280.50 or 0.003565.',
            'quote_currency.different' => 'Pick two different currencies.',
        ];
    }

    /** Normalised so "280.500" and "280.5" are stored the same. */
    public function rate(): string
    {
        return (string) BigDecimal::of($this->validated('rate'))->stripTrailingZeros();
    }

    public function rateDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('rate_date'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('rate'))) {
            $this->merge(['rate' => trim(str_replace(',', '', $this->input('rate')))]);
        }
    }
}
