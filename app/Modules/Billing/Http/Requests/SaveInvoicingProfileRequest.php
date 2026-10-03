<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The workspace's invoicing details. Only the listed fields can be set; the
 * workspace comes from the route and the logo from its own request.
 */
final class SaveInvoicingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', [InvoicingProfile::class, $this->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'billing_email' => ['required', 'string', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['required', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:80'],
            'tax_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => 'Add the business name your clients know you by.',
            'address_line1.required' => 'Add the first line of your address.',
        ];
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(InvoicingProfile::DETAIL_FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => blank($value = $this->input($field)) ? null : Str::squish((string) $value)])
            ->all());
    }
}
