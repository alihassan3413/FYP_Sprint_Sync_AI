<?php

declare(strict_types=1);

namespace App\Modules\People\Data;

enum TeamImportRowStatus: string
{
    case Ready = 'ready';
    case Warning = 'warning';
    case Skipped = 'skipped';
    case Error = 'error';

    public function willImport(): bool
    {
        return $this === self::Ready || $this === self::Warning;
    }
}
