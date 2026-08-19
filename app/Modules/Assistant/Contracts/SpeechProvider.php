<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use App\Modules\Assistant\Exceptions\SpeechException;

/**
 * Turns assistant text into spoken audio.
 *
 * Separate from {@see AiProvider} on purpose: the model that writes the reply
 * and the engine that voices it are independent choices. Anthropic has no
 * speech API, so Claude answers and something else speaks.
 */
interface SpeechProvider
{
    /**
     * Synthesize speech and return the raw audio bytes.
     *
     * @throws SpeechException
     */
    public function synthesize(string $text, ?string $voice = null): string;

    /** MIME type of the audio returned by {@see synthesize()}. */
    public function contentType(): string;

    /** Identifier for logs and metrics. e.g. 'openai'. */
    public function name(): string;

    public function isConfigured(): bool;
}
