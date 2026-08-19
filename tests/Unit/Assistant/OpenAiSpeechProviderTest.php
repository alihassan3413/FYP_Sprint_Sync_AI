<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Drivers\OpenAiSpeechProvider;
use App\Modules\Assistant\Exceptions\SpeechException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiSpeechProviderTest extends TestCase
{
    private function provider(string $model = 'gpt-4o-mini-tts', string $format = 'mp3'): OpenAiSpeechProvider
    {
        return new OpenAiSpeechProvider(
            apiKey: 'test-key',
            baseUrl: 'https://api.openai.com/v1',
            model: $model,
            defaultVoice: 'nova',
            format: $format,
            speed: 1.25,
            timeout: 30,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        $payload = [];

        Http::assertSent(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return true;
        });

        return $payload;
    }

    public function test_it_returns_the_raw_audio_bytes(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('MP3BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->assertSame('MP3BYTES', $this->provider()->synthesize('Hello there.'));
    }

    public function test_it_sends_the_configured_model_voice_and_format(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('MP3BYTES')]);

        $this->provider()->synthesize('Hello there.');

        $payload = $this->sentPayload();

        $this->assertSame('gpt-4o-mini-tts', $payload['model']);
        $this->assertSame('nova', $payload['voice']);
        $this->assertSame('mp3', $payload['response_format']);
        $this->assertSame('Hello there.', $payload['input']);
    }

    public function test_an_explicit_voice_overrides_the_configured_default(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('MP3BYTES')]);

        $this->provider()->synthesize('Hello there.', 'onyx');

        $this->assertSame('onyx', $this->sentPayload()['voice']);
    }

    public function test_the_speed_control_is_omitted_for_models_that_reject_it(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('MP3BYTES')]);

        $this->provider(model: 'gpt-4o-mini-tts')->synthesize('Hello there.');

        $this->assertArrayNotHasKey('speed', $this->sentPayload());
    }

    public function test_the_speed_control_is_sent_for_the_classic_models(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('MP3BYTES')]);

        $this->provider(model: 'tts-1')->synthesize('Hello there.');

        $this->assertSame(1.25, $this->sentPayload()['speed']);
    }

    public function test_the_content_type_follows_the_configured_format(): void
    {
        $this->assertSame('audio/mpeg', $this->provider(format: 'mp3')->contentType());
        $this->assertSame('audio/ogg', $this->provider(format: 'opus')->contentType());
        $this->assertSame('audio/wav', $this->provider(format: 'wav')->contentType());
    }

    public function test_it_reports_an_api_failure_as_a_speech_exception(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad voice']], 400)]);

        $this->expectException(SpeechException::class);
        $this->expectExceptionMessageMatches('/bad voice/');

        $this->provider()->synthesize('Hello there.');
    }

    public function test_it_reports_an_empty_response_as_a_speech_exception(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('', 200)]);

        $this->expectException(SpeechException::class);

        $this->provider()->synthesize('Hello there.');
    }

    public function test_it_is_not_configured_without_an_api_key(): void
    {
        $provider = new OpenAiSpeechProvider('', 'https://api.openai.com/v1', 'tts-1', 'nova');

        $this->assertFalse($provider->isConfigured());

        $this->expectException(SpeechException::class);

        $provider->synthesize('Hello there.');
    }
}
