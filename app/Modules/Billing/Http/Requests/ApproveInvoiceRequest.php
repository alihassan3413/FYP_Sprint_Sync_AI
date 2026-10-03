<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * version is the invoice version the reviewer was looking at, so an approval
 * never covers numbers the reviewer did not see.
 */
final class ApproveInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->invoice()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1']];
    }

    public function invoice(): Invoice
    {
        return $this->route('invoice');
    }
}
