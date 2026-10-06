<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A timer is running, paused or on (paid) lunch while active, then stopped
 * for good. Only these changes exist: running ⇄ paused, running ⇄ lunch, and
 * any active state → stopped. Lunch can't be paused (end lunch first), and an
 * idle pause only happens while running.
 */
#[TypeScript]
enum TimerState: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Lunch = 'lunch';
    case Stopped = 'stopped';

    public function isActive(): bool
    {
        return $this !== self::Stopped;
    }
}
