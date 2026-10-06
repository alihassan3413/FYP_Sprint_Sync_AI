<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;

final class StartTimerRequest extends TimeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('track', [TimeEntry::class, $this->workspace()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->projectRules(),
            'billable' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'operation_key' => self::KEY_RULES,
        ];
    }
}
