<?php

declare(strict_types=1);

namespace App\Modules\People\Data;

enum TeamImportStatus: string
{
    case Validated = 'validated';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Expired], true);
    }
}
