<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\AdjustmentType;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Data\StoreBillingPlanData;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\DecimalAmount;
use App\Modules\Billing\Support\InvoiceCalculator;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Validation shared by creating and editing a recurring invoice.
 *
 * The owner picks one day, the send day. The generation day is derived, never
 * accepted from the request: fixed plans generate on the send day, hourly plans
 * on the 1st (see BillingSchedule).
 *
 * Amounts arrive as decimal strings ("2000.00", "5.55", "2") and are converted
 * with string arithmetic only, so no float ever reaches the database. Team
 * members are referenced by public_id and must belong to this workspace.
 */
abstract class BillingPlanRequest extends FormRequest
{
    public const MAX_LINES = 50;

    public const MAX_ADJUSTMENTS = 10;

    public const MAX_DUE_DAYS = 120;

    /** Up to 999,999,999.99: far beyond any invoice, and safely inside a 64-bit integer through every calculation. */
    private const MAX_WHOLE_DIGITS = 9;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'pricing_mode' => ['required', Rule::enum(PricingMode::class)],
            'send_day' => ['required', 'integer', 'between:1,28'],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],
            'due_in_days' => ['required', 'integer', 'between:0,'.self::MAX_DUE_DAYS],
            'delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
            'reminder_days_before' => [
                Rule::requiredIf(fn () => $this->needsReminder()),
                'nullable',
                'integer',
                Rule::in(DeliveryMode::reminderOptions()),
            ],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.person' => [
                Rule::requiredIf(fn () => $this->isHourly()),
                'nullable',
                'string',
                'distinct',
                Rule::exists('people', 'public_id')->where('workspace_id', $this->workspace()->id),
            ],
            'lines.*.description' => ['required', 'string', 'max:160'],
            'lines.*.role_label' => ['nullable', 'string', 'max:120'],
            'lines.*.unit_price' => ['required', 'string', $this->amountRule(allowZero: ! $this->isHourly())],

            'adjustments' => ['nullable', 'array', 'max:'.self::MAX_ADJUSTMENTS],
            'adjustments.*.kind' => ['required', Rule::enum(AdjustmentKind::class)],
            'adjustments.*.type' => ['required', Rule::enum(AdjustmentType::class)],
            'adjustments.*.label' => ['required', 'string', 'max:80'],
            'adjustments.*.value' => ['required', 'string', $this->adjustmentValueRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one team member or item.',
            'lines.min' => 'Add at least one team member or item.',
            'lines.*.person.required' => 'Hourly invoices bill team members. Pick someone from your team.',
            'lines.*.person.distinct' => 'This team member is already on the invoice.',
            'lines.*.person.exists' => 'That team member is not part of this workspace.',
            'lines.*.description.required' => 'Every line needs a name.',
            'reminder_days_before.required' => 'Choose when we should remind you.',
            'send_day.between' => 'Pick a day between the 1st and the 28th.',
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->client()->isArchived()) {
                $validator->errors()->add('name', 'Restore this client before changing its recurring invoices.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->nameIsTaken()) {
                $validator->errors()->add('name', "{$this->client()->name} already has a recurring invoice called \"{$this->string('name')->trim()}\".");
            }

            if (! $this->isHourly()) {
                $this->checkTotalsCanBeCalculated($validator);
            }
        }];
    }

    abstract protected function currentPlan(): ?BillingPlan;

    public function client(): Client
    {
        return $this->route('client');
    }

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function toDTO(): StoreBillingPlanData
    {
        $validated = $this->validated();
        $personIds = $this->personIds();

        $mode = PricingMode::from($validated['pricing_mode']);
        $sendDay = (int) $validated['send_day'];

        return new StoreBillingPlanData(
            name: $validated['name'],
            currency: Currency::from($validated['currency']),
            pricing_mode: $mode,
            generation_day: BillingSchedule::generationDayFor($mode, $sendDay),
            send_day: $sendDay,
            billing_period: BillingPeriod::from($validated['billing_period']),
            due_in_days: (int) $validated['due_in_days'],
            delivery_mode: DeliveryMode::from($validated['delivery_mode']),
            reminder_days_before: isset($validated['reminder_days_before']) ? (int) $validated['reminder_days_before'] : null,
            lines: array_values(array_map(fn (array $line) => [
                'person_id' => isset($line['person']) ? $personIds->get($line['person']) : null,
                'description' => $line['description'],
                'role_label' => $line['role_label'] ?? null,
                'unit_price_minor' => DecimalAmount::scaled($line['unit_price'], 2),
            ], $validated['lines'])),
            adjustments: array_values(array_map(fn (array $adjustment) => [
                'kind' => AdjustmentKind::from($adjustment['kind']),
                'type' => AdjustmentType::from($adjustment['type']),
                'label' => $adjustment['label'],
                'value' => DecimalAmount::scaled($adjustment['value'], 2),
            ], $validated['adjustments'] ?? [])),
        );
    }

    /**
     * Hourly plans always bill the month that just ended and are reviewed when
     * its hours are in, so they never carry a "days before" reminder. Auto-send
     * plans have no reminder either. Blank optional strings become null.
     */
    protected function prepareForValidation(): void
    {
        $hourly = $this->input('pricing_mode') === PricingMode::Hourly->value;
        $auto = $this->input('delivery_mode') === DeliveryMode::AutoSend->value;

        $this->merge([
            'name' => Str::squish((string) $this->input('name')),
            'billing_period' => $hourly ? BillingPeriod::PreviousMonth->value : $this->input('billing_period'),
            'reminder_days_before' => $hourly || $auto ? null : $this->input('reminder_days_before'),
            'lines' => array_map(fn ($line) => is_array($line) ? [
                ...$line,
                'person' => blank($line['person'] ?? null) ? null : Str::lower((string) $line['person']),
                'description' => Str::squish((string) ($line['description'] ?? '')),
                'role_label' => blank($line['role_label'] ?? null) ? null : Str::squish((string) $line['role_label']),
                'unit_price' => self::decimalString($line['unit_price'] ?? null),
            ] : $line, (array) $this->input('lines', [])),
            'adjustments' => array_map(fn ($adjustment) => is_array($adjustment) ? [
                ...$adjustment,
                'label' => Str::squish((string) ($adjustment['label'] ?? '')),
                'value' => self::decimalString($adjustment['value'] ?? null),
            ] : $adjustment, (array) $this->input('adjustments', [])),
        ]);
    }

    private function isHourly(): bool
    {
        return $this->input('pricing_mode') === PricingMode::Hourly->value;
    }

    private function needsReminder(): bool
    {
        return ! $this->isHourly() && $this->input('delivery_mode') === DeliveryMode::ReviewBeforeSending->value;
    }

    private function amountRule(bool $allowZero): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowZero) {
            if (! is_string($value) || ! DecimalAmount::isValid($value, 2, self::MAX_WHOLE_DIGITS)) {
                $fail('Enter an amount like 1200 or 5.55.');

                return;
            }

            if (! $allowZero && DecimalAmount::scaled($value, 2) === 0) {
                $fail('The hourly rate must be more than zero.');
            }
        };
    }

    private function adjustmentValueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $index = (int) explode('.', $attribute)[1];
            $isPercentage = $this->input("adjustments.{$index}.type") === AdjustmentType::Percentage->value;

            if (! is_string($value) || ! DecimalAmount::isValid($value, 2, $isPercentage ? 3 : self::MAX_WHOLE_DIGITS)) {
                $fail($isPercentage ? 'Enter a percentage like 2 or 2.5.' : 'Enter an amount like 50 or 49.99.');

                return;
            }

            $scaled = DecimalAmount::scaled($value, 2);

            if ($scaled === 0) {
                $fail('The amount must be more than zero.');
            } elseif ($isPercentage && $scaled > 10_000) {
                $fail('A percentage cannot be more than 100%.');
            }
        };
    }

    /**
     * A fixed plan's monthly total is known now, so a discount larger than the
     * subtotal is caught here rather than on the first invoice.
     */
    private function checkTotalsCanBeCalculated(Validator $validator): void
    {
        $dto = $this->toDTO();

        try {
            app(InvoiceCalculator::class)->calculate(
                $dto->currency,
                array_map(fn (array $line) => ['quantity' => 100, 'unit_price' => $line['unit_price_minor']], $dto->lines),
                $dto->adjustments,
            );
        } catch (InvalidArgumentException $exception) {
            $validator->errors()->add('adjustments', $exception->getMessage());
        }
    }

    private function nameIsTaken(): bool
    {
        return $this->client()->billingPlans()
            ->whereRaw('lower(name) = ?', [Str::lower($this->string('name')->toString())])
            ->when($this->currentPlan(), fn ($query, BillingPlan $plan) => $query->whereKeyNot($plan->getKey()))
            ->exists();
    }

    /**
     * @return Collection<string, int>
     */
    private function personIds(): Collection
    {
        $publicIds = array_filter(array_column($this->validated('lines'), 'person'));

        return Person::query()
            ->where('workspace_id', $this->workspace()->id)
            ->whereIn('public_id', $publicIds)
            ->pluck('id', 'public_id');
    }

    private static function decimalString(mixed $value): mixed
    {
        return is_int($value) ? (string) $value : (is_string($value) ? trim(str_replace(',', '', $value)) : $value);
    }
}
