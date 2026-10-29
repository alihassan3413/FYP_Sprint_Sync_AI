<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MarkInvoicePaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recordPayment', $this->route('invoice')) ?? false;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'uuid'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.CarbonImmutable::now($this->route('workspace')->timezone)->toDateString()],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function receivedOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('received_on'));
    }
}
