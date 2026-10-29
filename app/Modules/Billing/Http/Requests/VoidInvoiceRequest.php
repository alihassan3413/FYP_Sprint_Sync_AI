<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VoidInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('invoice')) ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:200']];
    }
}
