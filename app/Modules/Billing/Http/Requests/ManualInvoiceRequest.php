<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\AdjustmentType;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\DecimalAmount;
use App\Modules\Workspace\Models\Workspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ManualInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice
            ? ($this->user()?->can('update', $invoice) ?? false)
            : ($this->user()?->can('create', [Invoice::class, $this->workspace()]) ?? false);
    }

    public function rules(): array
    {
        $creating = ! $this->route('invoice') instanceof Invoice;

        return [
            'idempotency_key' => [$creating ? 'required' : 'prohibited', 'string', 'uuid'],
            'version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'recipient_type' => ['required', Rule::in(['client', 'custom'])],
            'client' => ['required_if:recipient_type,client', 'nullable', 'string', Rule::exists('clients', 'public_id')->where('workspace_id', $this->workspace()->id)->whereNull('archived_at')],
            'bill_to.name' => ['required_if:recipient_type,custom', 'nullable', 'string', 'max:120'],
            'bill_to.billing_email' => ['required_if:recipient_type,custom', 'nullable', 'email', 'max:254'],
            'bill_to.address' => ['nullable', 'string', 'max:500'],
            'bill_to.tax_id' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'issue_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:160'],
            'lines.*.quantity' => ['required', 'string', $this->decimal(5, 'Quantity', true)],
            'lines.*.rate' => ['required', 'string', $this->decimal(9, 'Rate', false)],
            'adjustments' => ['present', 'array', 'max:10'],
            'adjustments.*.kind' => ['required', Rule::enum(AdjustmentKind::class)],
            'adjustments.*.type' => ['required', Rule::enum(AdjustmentType::class)],
            'adjustments.*.value' => ['required', 'string', $this->decimal(9, 'Amount', true)],
            'adjustments.*.label' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $issue = $this->input('issue_date');
                $due = $this->input('due_date');
                if (is_string($issue) && is_string($due) && strtotime($due) - strtotime($issue) > 365 * 86400) {
                    $validator->errors()->add('due_date', 'The due date must be within a year of the issue date.');
                }

                foreach ((array) $this->input('adjustments', []) as $index => $adjustment) {
                    if (($adjustment['type'] ?? null) === AdjustmentType::Percentage->value && is_string($adjustment['value'] ?? null) && DecimalAmount::isValid($adjustment['value'], 2, 9) && DecimalAmount::scaled($adjustment['value'], 2) > 10000) {
                        $validator->errors()->add("adjustments.{$index}.value", 'A percentage cannot be more than 100.');
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'client.required_if' => 'Choose a client, or enter a custom recipient.',
            'bill_to.name.required_if' => 'Enter who the invoice is for.',
            'bill_to.billing_email.required_if' => 'Enter the recipient\'s billing email.',
            'due_date.after_or_equal' => 'The due date cannot be before the issue date.',
            'lines.required' => 'Add at least one line.',
        ];
    }

    public function details(): array
    {
        $workspace = $this->workspace();
        $custom = $this->validated('recipient_type') === 'custom';

        return [
            'client_id' => $custom ? null : $workspace->clients()->where('public_id', $this->validated('client'))->value('id'),
            'bill_to' => $custom ? [
                'name' => $this->validated('bill_to.name'),
                'billing_email' => Str::lower($this->validated('bill_to.billing_email')),
                'cc_emails' => [],
                'address' => $this->validated('bill_to.address'),
                'tax_id' => $this->validated('bill_to.tax_id'),
            ] : null,
            'title' => $this->validated('title'),
            'currency' => Currency::from($this->validated('currency')),
            'issue_date' => $this->validated('issue_date'),
            'due_date' => $this->validated('due_date'),
            'notes' => $this->validated('notes'),
            'lines' => array_map(fn (array $line) => [
                'description' => $line['description'],
                'quantity_centi' => DecimalAmount::scaled($line['quantity'], 2),
                'unit_price_minor' => DecimalAmount::scaled($line['rate'], 2),
            ], array_values($this->validated('lines'))),
            'adjustments' => array_map(fn (array $adjustment) => [
                'kind' => AdjustmentKind::from($adjustment['kind']),
                'type' => AdjustmentType::from($adjustment['type']),
                'value' => DecimalAmount::scaled($adjustment['value'], 2),
                'label' => $adjustment['label'] ?? null,
            ], array_values($this->validated('adjustments', []))),
        ];
    }

    private function decimal(int $wholeDigits, string $label, bool $positive): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($wholeDigits, $label, $positive) {
            if (! is_string($value) || ! DecimalAmount::isValid($value, 2, $wholeDigits) || ($positive && DecimalAmount::scaled($value, 2) === 0)) {
                $fail($positive ? "{$label} must be a number above zero, with up to 2 decimals." : "{$label} must be a number with up to 2 decimals.");
            }
        };
    }

    private function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (mixed $value) => is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim(str_replace(',', '', $value)) : $value);
        $text = fn (mixed $value) => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $lines = array_map(fn ($line) => is_array($line) ? [...$line, 'quantity' => $clean($line['quantity'] ?? null), 'rate' => $clean($line['rate'] ?? null), 'description' => Str::squish((string) ($line['description'] ?? ''))] : $line, (array) $this->input('lines', []));
        $adjustments = array_map(fn ($adjustment) => is_array($adjustment) ? [...$adjustment, 'value' => $clean($adjustment['value'] ?? null)] : $adjustment, (array) $this->input('adjustments', []));
        $billTo = (array) $this->input('bill_to', []);

        $this->merge([
            'lines' => $lines,
            'adjustments' => $adjustments,
            'client' => $text($this->input('client')),
            'notes' => $text($this->input('notes')),
            'bill_to' => [
                'name' => $text($billTo['name'] ?? null),
                'billing_email' => $text($billTo['billing_email'] ?? null),
                'address' => $text($billTo['address'] ?? null),
                'tax_id' => $text($billTo['tax_id'] ?? null),
            ],
        ]);
    }
}
