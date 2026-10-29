<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class SendManualInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send', $this->route('invoice')) ?? false;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1', 'max:10'],
            'to.*' => ['required', 'email', 'max:254', 'distinct'],
            'cc' => ['present', 'array', 'max:10'],
            'cc.*' => ['required', 'email', 'max:254', 'distinct'],
            'bcc' => ['present', 'array', 'max:10'],
            'bcc.*' => ['required', 'email', 'max:254', 'distinct'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['to.required' => 'Add at least one recipient.', 'to.*.email' => 'Each recipient must be a valid email address.'];
    }

    public function addresses(string $field): array
    {
        return array_values(array_unique($this->validated($field, [])));
    }

    protected function prepareForValidation(): void
    {
        $split = fn (mixed $value) => array_values(array_filter(array_map(fn ($email) => Str::lower(trim((string) $email)), is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value)), fn ($email) => $email !== ''));

        $this->merge([
            'to' => $split($this->input('to', [])),
            'cc' => $split($this->input('cc', [])),
            'bcc' => $split($this->input('bcc', [])),
            'message' => is_string($this->input('message')) && trim($this->input('message')) !== '' ? trim($this->input('message')) : null,
        ]);
    }
}
