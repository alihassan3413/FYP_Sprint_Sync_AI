<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Support;

use App\Modules\Meetings\Contracts\TranscriptionProvider;
use App\Modules\Meetings\Data\TranscriptionResult;
use App\Modules\Meetings\Exceptions\TranscriptionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenAiTranscriptionProvider implements TranscriptionProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return ! empty(config('transcription.openai.api_key'));
    }

    public function transcribe(string $absolutePath, string $filename): TranscriptionResult
    {
        if (! $this->isConfigured()) {
            throw TranscriptionException::notConfigured($this->name());
        }

        $model = (string) config('transcription.openai.model');
        $audio = @file_get_contents($absolutePath);

        if ($audio === false || $audio === '') {
            throw TranscriptionException::providerFailed($this->name(), 'the recording file was empty or unreadable');
        }

        try {
            $response = Http::withToken((string) config('transcription.openai.api_key'))
                ->timeout((int) config('transcription.openai.timeout'))
                ->attach('file', $audio, $filename)
                ->post(rtrim((string) config('transcription.openai.base_url'), '/').'/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'verbose_json',
                ]);
        } catch (Throwable $e) {
            throw TranscriptionException::providerFailed($this->name(), $e->getMessage());
        }

        if ($response->failed()) {
            throw TranscriptionException::providerFailed(
                $this->name(),
                (string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            );
        }

        $text = trim((string) ($response->json('text') ?? ''));

        if ($text === '') {
            throw TranscriptionException::emptyTranscript($this->name());
        }

        return new TranscriptionResult(
            text: $text,
            language: $response->json('language'),
            confidence: TranscriptionConfidence::fromSegments($response->json('segments')),
            provider: $this->name(),
            model: $model,
        );
    }
}
