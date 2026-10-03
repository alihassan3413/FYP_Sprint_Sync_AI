<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\StoreClientData;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation shared by creating and editing a client. Only the fields listed
 * here ever reach the model: workspace_id comes from the route and archived_at
 * from its own action, whatever the request body says.
 */
abstract class ClientDetailsRequest extends FormRequest
{
    public const MAX_CC_EMAILS = 5;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120', $this->uniqueName()],
            'billing_email' => ['required', 'string', 'email', 'max:254'],
            'cc_emails' => ['nullable', 'array', 'max:'.self::MAX_CC_EMAILS],
            'cc_emails.*' => ['string', 'email', 'max:254', 'distinct:ignore_case'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a client with this name.',
            'cc_emails.max' => 'Add at most '.self::MAX_CC_EMAILS.' extra addresses.',
            'cc_emails.*.email' => 'Each extra address must be a valid email.',
            'currency.enum' => 'Choose one of the listed currencies.',
        ];
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function toDTO(): StoreClientData
    {
        $validated = $this->validated();

        return new StoreClientData(
            name: $validated['name'],
            billing_email: $validated['billing_email'],
            currency: Currency::from($validated['currency']),
            cc_emails: array_values($validated['cc_emails'] ?? []),
            address: $validated['address'] ?? null,
            tax_id: $validated['tax_id'] ?? null,
        );
    }

    abstract protected function uniqueName(): Unique;

    /**
     * The form sends extra addresses as one comma-separated field; the API
     * shape is a list. Accept both.
     */
    protected function prepareForValidation(): void
    {
        $cc = $this->input('cc_emails');

        if (is_string($cc)) {
            $this->merge([
                'cc_emails' => array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $cc) ?: []))),
            ]);
        }

        foreach (['name', 'billing_email', 'address', 'tax_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) === '' ? null : trim($this->input($field))]);
            }
        }
    }
}
