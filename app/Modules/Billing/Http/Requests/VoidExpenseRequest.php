<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Expenses\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

final class VoidExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', [Expense::class, $this->route('workspace')]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:200']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Say briefly why, for example "entered twice".'];
    }
}
