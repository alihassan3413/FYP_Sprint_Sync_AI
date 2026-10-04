<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;

/** Pause, resume or stop the signed-in person's timer. */
final class TimerOperationRequest extends TimeRequest
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
            'operation_key' => self::KEY_RULES,
            'session' => ['nullable', 'string', 'ulid'],
        ];
    }
}
