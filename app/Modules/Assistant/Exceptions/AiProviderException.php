<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Exceptions;

use RuntimeException;

final class AiProviderException extends RuntimeException
{
    public static function unknownDriver(string $driver): self
    {
        return new self("Unknown assistant driver [{$driver}].");
    }

    public static function missingApiKey(string $driver): self
    {
        return new self("No API key configured for assistant driver [{$driver}].");
    }

    public static function requestFailed(string $message): self
    {
        return new self("The assistant provider request failed: {$message}");
    }
}
