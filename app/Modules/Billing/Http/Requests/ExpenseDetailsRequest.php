<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Support\DecimalAmount;
use App\Modules\Expenses\Data\ExpenseCategory;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The fields an expense and a monthly expense share. The amount is a decimal
 * string in the chosen currency, converted with string arithmetic only; the
 * client and project are optional and must belong to this workspace.
 */
abstract class ExpenseDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', [Expense::class, $this->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:160'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! DecimalAmount::isValid($value, 2, 12) || DecimalAmount::scaled($value, 2) === 0) {
                    $fail('Enter an amount more than zero, like 300000 or 49.99.');
                }
            }],
            'currency' => ['required', Rule::enum(Currency::class)],
            'client' => ['nullable', 'string', Rule::exists('clients', 'public_id')->where('workspace_id', $this->workspace()->id)],
            'project' => ['nullable', 'string', Rule::exists('projects', 'public_id')->where('workspace_id', $this->workspace()->id)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{category: ExpenseCategory, description: string, vendor: ?string, amount_minor: int, currency: Currency, client_id: ?int, project_id: ?int, notes: ?string}
     */
    public function details(): array
    {
        $workspace = $this->workspace();

        return [
            'category' => ExpenseCategory::from($this->validated('category')),
            'description' => $this->validated('description'),
            'vendor' => $this->validated('vendor'),
            'amount_minor' => DecimalAmount::scaled($this->validated('amount'), 2),
            'currency' => Currency::from($this->validated('currency')),
            'client_id' => $this->validated('client') === null ? null : $workspace->clients()->where('public_id', $this->validated('client'))->value('id'),
            'project_id' => $this->validated('project') === null ? null : $workspace->projects()->where('public_id', $this->validated('project'))->value('id'),
            'notes' => $this->validated('notes'),
        ];
    }

    protected function dateField(string $field): ?CarbonImmutable
    {
        $value = $this->validated($field);

        return $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }

    protected function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        $text = fn (string $field) => blank($this->input($field)) ? null : Str::squish((string) $this->input($field));

        $this->merge([
            'amount' => is_int($amount) ? (string) $amount : (is_string($amount) ? trim(str_replace(',', '', $amount)) : $amount),
            'description' => $text('description'),
            'vendor' => $text('vendor'),
            'client' => $text('client'),
            'project' => $text('project'),
            'notes' => blank($this->input('notes')) ? null : trim((string) $this->input('notes')),
        ]);
    }
}
