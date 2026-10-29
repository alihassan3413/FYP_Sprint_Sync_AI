<?php

declare(strict_types=1);

namespace App\Modules\People\Data;

enum TeamImportOutcome: string
{
    case Invited = 'invited';
    case Linked = 'linked';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::Linked => 'Linked to existing member',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }
}
