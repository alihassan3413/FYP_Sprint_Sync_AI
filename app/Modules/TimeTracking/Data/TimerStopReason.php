<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum TimerStopReason: string
{
    case ShiftEnded = 'shift_ended';
    case MaxDuration = 'max_duration';

    public function label(): string
    {
        return match ($this) {
            self::ShiftEnded => 'Stopped automatically after the shift ended',
            self::MaxDuration => 'Stopped automatically after running too long',
        };
    }
}
