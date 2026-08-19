<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Data;

enum TranscriptSource: string
{
    case Recording = 'recording';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Recording => 'Automatic transcription',
            self::Manual => 'Manual upload',
        };
    }
}
