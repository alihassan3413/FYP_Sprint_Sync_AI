<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Exceptions;

use RuntimeException;

final class TranscriptionException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("The {$provider} transcription provider is not configured.");
    }

    public static function providerFailed(string $provider, string $reason): self
    {
        return new self("{$provider} could not transcribe this recording: {$reason}");
    }

    public static function emptyTranscript(string $provider): self
    {
        return new self("{$provider} returned an empty transcript for this recording.");
    }
}
