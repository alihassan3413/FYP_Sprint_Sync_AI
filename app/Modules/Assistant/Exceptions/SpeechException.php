<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Exceptions;

use RuntimeException;

final class SpeechException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("The [{$provider}] speech provider is not configured.");
    }

    public static function providerFailed(string $provider, string $reason): self
    {
        return new self("The [{$provider}] speech provider failed: {$reason}");
    }

    public static function emptyAudio(string $provider): self
    {
        return new self("The [{$provider}] speech provider returned no audio.");
    }
}
