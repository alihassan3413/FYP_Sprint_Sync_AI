<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;

/**
 * SprintSync Desktop's idle pause: when the computer was last used
 * (idle_since) and when the threshold was reached (occurred_at). Same trust
 * limits as other synced timer changes.
 */
final class IdlePauseRequest extends TimeRequest
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
            'session' => ['required', 'string', 'ulid'],
            'idle_since' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(2)->toIso8601String(), 'after:'.now()->subHours(TimerOperationRequest::MAX_DELAY_HOURS)->toIso8601String()],
            'occurred_at' => ['nullable', 'date', 'after_or_equal:idle_since', 'before_or_equal:'.now()->addMinutes(2)->toIso8601String()],
        ];
    }

    public function idleSince(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('idle_since'))->utc();
    }

    public function detectedAt(): ?CarbonImmutable
    {
        $value = $this->validated('occurred_at');

        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc();
    }
}
