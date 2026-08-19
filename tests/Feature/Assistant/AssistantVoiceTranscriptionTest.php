<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Meetings\Contracts\TranscriptionProvider;
use App\Modules\Meetings\Data\TranscriptionResult;
use App\Modules\Meetings\Exceptions\TranscriptionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AssistantVoiceTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  \Closure(string, string): TranscriptionResult|null  $onTranscribe
     */
    private function fakeTranscriber(?\Closure $onTranscribe = null, bool $configured = true): void
    {
        $this->app->bind(TranscriptionProvider::class, fn () => new class($onTranscribe, $configured) implements TranscriptionProvider
        {
            public function __construct(
                private readonly ?\Closure $onTranscribe,
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

            public function transcribe(string $absolutePath, string $filename): TranscriptionResult
            {
                if ($this->onTranscribe !== null) {
                    return ($this->onTranscribe)($absolutePath, $filename);
                }

                return new TranscriptionResult(
                    text: 'Schedule a standup tomorrow at nine.',
                    language: 'english',
                    confidence: 92,
                    provider: 'fake',
                    model: 'whisper-1',
                );
            }
        });
    }

    private function recording(string $name = 'speech.webm', string $mime = 'audio/webm'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 24, $mime);
    }

    public function test_guests_cannot_transcribe_audio(): void
    {
        $this->postJson(route('assistant.voice.transcribe'), [
            'audio' => $this->recording(),
        ])->assertUnauthorized();
    }

    public function test_it_returns_the_transcript_for_a_recording(): void
    {
        $this->fakeTranscriber();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.transcribe'), ['audio' => $this->recording()])
            ->assertOk()
            ->assertJson([
                'text' => 'Schedule a standup tomorrow at nine.',
                'language' => 'english',
                'confidence' => 92,
            ]);
    }

    public function test_the_recording_is_required(): void
    {
        $this->fakeTranscriber();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.transcribe'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audio');
    }

    public function test_a_non_audio_upload_is_rejected(): void
    {
        $this->fakeTranscriber();

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.transcribe'), [
                'audio' => UploadedFile::fake()->create('payload.php', 8, 'text/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audio');
    }

    public function test_an_oversized_recording_is_rejected(): void
    {
        $this->fakeTranscriber();

        config(['assistant.voice.max_upload_kilobytes' => 16]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.transcribe'), [
                'audio' => UploadedFile::fake()->create('speech.webm', 64, 'audio/webm'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audio');
    }

    public function test_it_reports_a_friendly_error_when_transcription_fails(): void
    {
        $this->fakeTranscriber(fn () => throw TranscriptionException::emptyTranscript('fake'));

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.transcribe'), ['audio' => $this->recording()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'I could not make out that recording. Please try again.');
    }

    public function test_it_reports_unavailable_when_no_provider_is_configured(): void
    {
        $this->fakeTranscriber(configured: false);

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.transcribe'), ['audio' => $this->recording()])
            ->assertStatus(503);
    }

    public function test_the_recording_is_deleted_once_it_has_been_transcribed(): void
    {
        $capturedPath = null;

        $this->fakeTranscriber(function (string $path) use (&$capturedPath) {
            $capturedPath = $path;

            return new TranscriptionResult('Hello there.', 'english', 90, 'fake', 'whisper-1');
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('assistant.voice.transcribe'), ['audio' => $this->recording()])
            ->assertOk();

        $this->assertNotNull($capturedPath);
        $this->assertFileDoesNotExist($capturedPath);
    }

    public function test_the_endpoint_is_authenticated_verified_and_throttled(): void
    {
        $middleware = Route::getRoutes()
            ->getByName('assistant.voice.transcribe')
            ->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
        $this->assertContains('throttle:assistant-voice', $middleware);
    }
}
