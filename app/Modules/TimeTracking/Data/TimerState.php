<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A timer is running or paused while active, then stopped for good. Only
 * running → paused, paused → running, and either → stopped exist.
 */
#[TypeScript]
enum TimerState: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';

    public function isActive(): bool
    {
        return $this !== self::Stopped;
    }
}
