<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\PaymentMethod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\DecimalAmount;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * "Record payment". The amount arrives as a decimal string and is converted
 * with string arithmetic only. There is deliberately no currency field: a
 * payment is always in the invoice's currency. idempotency_key is generated
 * by the form each time it opens.
 */
final class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recordPayment', $this->invoice()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'uuid'],
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! DecimalAmount::isValid($value, 2, 9) || DecimalAmount::scaled($value, 2) === 0) {
                    $fail('Enter an amount more than zero, like 2000 or 3059.20.');
                }
            }],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$this->today()->toDateString()],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'received_on.before_or_equal' => 'The date received cannot be in the future.',
            'received_on.date_format' => 'Pick the date the money arrived.',
        ];
    }

    public function invoice(): Invoice
    {
        return $this->route('invoice');
    }

    public function amountMinor(): int
    {
        return DecimalAmount::scaled($this->validated('amount'), 2);
    }

    public function receivedOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('received_on'));
    }

    /** "Today" is the workspace's calendar day, not the server's. */
    private function today(): CarbonImmutable
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return CarbonImmutable::now($workspace->timezone)->startOfDay();
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');

        $this->merge([
            'amount' => is_int($amount) ? (string) $amount : (is_string($amount) ? trim(str_replace([',', '$'], '', $amount)) : $amount),
            'reference' => blank($this->input('reference')) ? null : Str::squish((string) $this->input('reference')),
            'note' => blank($this->input('note')) ? null : trim((string) $this->input('note')),
        ]);
    }
}
