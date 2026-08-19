<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Drivers;

use App\Modules\Assistant\Contracts\SpeechProvider;
use App\Modules\Assistant\Exceptions\SpeechException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Neural text-to-speech via OpenAI's audio/speech endpoint.
 *
 * Returns audio bytes rather than a URL: the clips are short-lived and
 * per-utterance, so streaming them straight back to the caller avoids storing
 * anything the user said or was told.
 */
final class OpenAiSpeechProvider implements SpeechProvider
{
    /** @var array<string, string> */
    private const CONTENT_TYPES = [
        'mp3' => 'audio/mpeg',
        'opus' => 'audio/ogg',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
        'pcm' => 'audio/pcm',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $defaultVoice,
        private readonly string $format = 'mp3',
        private readonly float $speed = 1.0,
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function contentType(): string
    {
        return self::CONTENT_TYPES[$this->format] ?? 'audio/mpeg';
    }

    public function synthesize(string $text, ?string $voice = null): string
    {
        if (! $this->isConfigured()) {
            throw SpeechException::notConfigured($this->name());
        }

        $payload = [
            'model' => $this->model,
            'input' => $text,
            'voice' => $voice ?? $this->defaultVoice,
            'response_format' => $this->format,
        ];

        // The speed control is rejected by the gpt-4o-*-tts models, which pace
        // themselves from the text instead.
        if (! str_starts_with($this->model, 'gpt-4o')) {
            $payload['speed'] = $this->speed;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->connectTimeout(5)
                ->post(rtrim($this->baseUrl, '/').'/audio/speech', $payload);
        } catch (Throwable $e) {
            throw SpeechException::providerFailed($this->name(), $e->getMessage());
        }

        if ($response->failed()) {
            throw SpeechException::providerFailed(
                $this->name(),
                (string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            );
        }

        $audio = $response->body();

        if ($audio === '') {
            throw SpeechException::emptyAudio($this->name());
        }

        return $audio;
    }
}
