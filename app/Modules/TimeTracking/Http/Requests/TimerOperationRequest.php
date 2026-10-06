<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;

/**
 * Pause, resume or stop the signed-in person's timer.
 *
 * occurred_at (optional) is when the person actually pressed the button, for
 * a change the desktop app queued while offline. The client is trusted only
 * within limits: no more than MAX_DELAY_HOURS in the past, no more than
 * MAX_AHEAD_SECONDS ahead (clock drift; clamped to now), and never before the
 * timer's last change (ActiveTimer). Without it the server clock is used.
 * The time it actually arrived is kept separately (updated_at, the audit log).
 */
final class TimerOperationRequest extends TimeRequest
{
    public const MAX_DELAY_HOURS = 72;

    public const MAX_AHEAD_SECONDS = 120;

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
            'occurred_at' => ['nullable', 'date', function (string $attribute, mixed $value, \Closure $fail) {
                $at = $this->occurredAt();

                if ($at === null || $at->greaterThan(now()->addSeconds(self::MAX_AHEAD_SECONDS))) {
                    $fail("That time is in the future. Check your computer's clock.");
                } elseif ($at->lessThan(now()->subHours(self::MAX_DELAY_HOURS))) {
                    $fail('That change is too old to sync. Correct the time on the SprintSync website instead.');
                }
            }],
        ];
    }

    public function occurredAt(): ?CarbonImmutable
    {
        $value = $this->input('occurred_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
