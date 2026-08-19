<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Data;

enum TranscriptStatus: string
{
    case AwaitingAudio = 'awaiting_audio';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingAudio => 'Awaiting recording',
            self::Queued => 'Queued for transcription',
            self::Processing => 'Transcribing',
            self::Completed => 'Transcribed',
            self::Failed => 'Transcription failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
