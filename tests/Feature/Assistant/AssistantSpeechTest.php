<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Contracts\SpeechProvider;
use App\Modules\Assistant\Exceptions\SpeechException;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AssistantSpeechTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSpeech(?Closure $onSynthesize = null, bool $configured = true): void
    {
        $this->app->bind(SpeechProvider::class, fn () => new class($onSynthesize, $configured) implements SpeechProvider
        {
            public function __construct(
                private readonly ?Closure $onSynthesize,
                private readonly bool $configured,
            ) {}

            public function name(): string
            {
                return 'fake';
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function contentType(): string
            {
                return 'audio/mpeg';
            }

            public function synthesize(string $text, ?string $voice = null): string
            {
                if ($this->onSynthesize !== null) {
                    return ($this->onSynthesize)($text, $voice);
                }

                return 'FAKE-MP3-BYTES';
            }
        });
    }

    public function test_guests_cannot_synthesize_speech(): void
    {
        $this->postJson(route('assistant.voice.speak'), ['text' => 'Hello'])->assertUnauthorized();
    }

    public function test_it_returns_audio_for_the_given_text(): void
    {
        $this->fakeSpeech();

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), ['text' => 'Your standup is scheduled.']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame('FAKE-MP3-BYTES', $response->getContent());
        $response->assertHeader('Content-Length', (string) strlen('FAKE-MP3-BYTES'));
    }

    public function test_the_utterance_is_never_stored_in_a_shared_cache(): void
    {
        $this->fakeSpeech();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), ['text' => 'Private reply.'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_text_is_required(): void
    {
        $this->fakeSpeech();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    public function test_text_beyond_the_configured_limit_is_rejected(): void
    {
        $this->fakeSpeech();
        config(['assistant.speech.max_characters' => 20]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), ['text' => str_repeat('a', 21)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    public function test_an_unknown_voice_is_rejected(): void
    {
        $this->fakeSpeech();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), ['text' => 'Hello', 'voice' => 'siri'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('voice');
    }

    public function test_a_permitted_voice_is_passed_through_to_the_provider(): void
    {
        $seen = null;

        $this->fakeSpeech(function (string $text, ?string $voice) use (&$seen) {
            $seen = $voice;

            return 'AUDIO';
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), ['text' => 'Hello', 'voice' => 'onyx'])
            ->assertOk();

        $this->assertSame('onyx', $seen);
    }

    public function test_a_provider_failure_reports_a_gateway_error_so_the_client_can_fall_back(): void
    {
        $this->fakeSpeech(fn () => throw SpeechException::providerFailed('fake', 'boom'));

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), ['text' => 'Hello'])
            ->assertStatus(502);
    }

    public function test_it_reports_unavailable_when_speech_is_not_configured(): void
    {
        $this->fakeSpeech(configured: false);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.speak'), ['text' => 'Hello'])
            ->assertStatus(503);
    }

    public function test_the_endpoint_is_authenticated_verified_and_throttled(): void
    {
        $middleware = Route::getRoutes()->getByName('assistant.voice.speak')->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
        $this->assertContains('throttle:assistant-voice', $middleware);
    }
}
