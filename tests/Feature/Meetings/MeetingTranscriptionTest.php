<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Models\User;
use App\Modules\Meetings\Actions\QueueCompletedMeetingTranscriptions;
use App\Modules\Meetings\Actions\TranscribeMeetingAction;
use App\Modules\Meetings\Data\TranscriptSource;
use App\Modules\Meetings\Data\TranscriptStatus;
use App\Modules\Meetings\Jobs\TranscribeMeetingJob;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingTranscript;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\TranscriptionFailedNotification;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MeetingTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $manager;

    private User $member;

    private Project $project;

    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        Http::preventStrayRequests();

        config(['transcription.driver' => 'openai', 'transcription.openai.api_key' => 'test-key']);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->manager = User::factory()->create();
        $this->workspace->users()->attach($this->manager->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($this->manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->meeting = Meeting::factory()
            ->forProject($this->project)
            ->createdBy($this->owner)
            ->scheduledAt(now()->subHours(3)->toDateTimeString())
            ->create(['duration_minutes' => 30]);
    }

    private function route(string $name): string
    {
        return route("workspace.projects.meetings.transcript.{$name}", [
            'workspace' => $this->workspace->slug,
            'project' => $this->project->id,
            'meeting' => $this->meeting->id,
        ]);
    }

    private function fakeWhisper(string $text = 'Standup transcript body.', float $logprob = -0.1, float $noSpeech = 0.01): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response([
                'text' => $text,
                'language' => 'english',
                'segments' => [
                    ['avg_logprob' => $logprob, 'no_speech_prob' => $noSpeech],
                ],
            ]),
        ]);
    }

    private function recordingFile(): UploadedFile
    {
        $file = UploadedFile::fake()->create('standup.mp3', 128, 'audio/mpeg');

        file_put_contents($file->getPathname(), str_repeat('a', 2048));

        return $file;
    }

    private function uploadRecording(User $actor): TestResponse
    {
        return $this->actingAs($actor)->post($this->route('recording'), [
            'recording' => $this->recordingFile(),
        ]);
    }

    public function test_a_completed_meeting_is_detected_and_a_transcript_record_is_created(): void
    {
        Queue::fake();

        $result = app(QueueCompletedMeetingTranscriptions::class)->handle();

        $this->assertSame(1, $result['detected']);
        $this->assertSame(0, $result['queued']);

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertSame($this->meeting->id, $transcript->meeting_id);
        $this->assertSame(TranscriptStatus::AwaitingAudio, $transcript->status);
        Queue::assertNothingPushed();
    }

    public function test_a_meeting_that_has_not_finished_is_not_detected(): void
    {
        MeetingTranscript::query()->delete();
        Meeting::query()->delete();

        Meeting::factory()
            ->forProject($this->project)
            ->createdBy($this->owner)
            ->scheduledAt(now()->addDay()->toDateTimeString())
            ->create(['duration_minutes' => 30]);

        $result = app(QueueCompletedMeetingTranscriptions::class)->handle();

        $this->assertSame(0, $result['detected']);
        $this->assertSame(0, MeetingTranscript::query()->count());
    }

    public function test_detection_is_idempotent_across_runs(): void
    {
        app(QueueCompletedMeetingTranscriptions::class)->handle();
        $second = app(QueueCompletedMeetingTranscriptions::class)->handle();

        $this->assertSame(0, $second['detected']);
        $this->assertSame(1, MeetingTranscript::query()->count());
    }

    public function test_a_completed_meeting_with_a_recording_is_queued_for_transcription(): void
    {
        Queue::fake();

        app(QueueCompletedMeetingTranscriptions::class)->handle();

        MeetingTranscript::query()->firstOrFail()->update(['audio_path' => 'meeting-recordings/x.mp3']);

        $result = app(QueueCompletedMeetingTranscriptions::class)->handle();

        $this->assertSame(1, $result['queued']);
        $this->assertSame(TranscriptStatus::Queued, MeetingTranscript::query()->firstOrFail()->status);
        Queue::assertPushed(TranscribeMeetingJob::class);
    }

    public function test_a_manager_can_upload_a_recording_and_it_is_transcribed(): void
    {
        $this->fakeWhisper();

        $this->uploadRecording($this->manager)->assertRedirect();

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertSame(TranscriptStatus::Completed, $transcript->status);
        $this->assertSame(TranscriptSource::Recording, $transcript->source);
        $this->assertSame('Standup transcript body.', $transcript->text);
        $this->assertSame('english', $transcript->language);
        $this->assertSame('openai', $transcript->provider);
        $this->assertNotNull($transcript->transcribed_at);
        Storage::disk('local')->assertExists($transcript->audio_path);
    }

    public function test_a_plain_project_member_cannot_upload_a_recording(): void
    {
        $this->uploadRecording($this->member)->assertForbidden();

        $this->assertSame(0, MeetingTranscript::query()->count());
    }

    public function test_a_user_from_another_workspace_cannot_upload_a_recording(): void
    {
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->uploadRecording($outsider)->assertNotFound();
    }

    public function test_a_non_audio_upload_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->post($this->route('recording'), [
                'recording' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors('recording');

        $this->assertSame(0, MeetingTranscript::query()->count());
    }

    public function test_a_low_confidence_transcript_is_flagged(): void
    {
        $this->fakeWhisper(logprob: -1.6, noSpeech: 0.4);

        $this->uploadRecording($this->manager);

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertTrue($transcript->is_low_confidence);
        $this->assertNotNull($transcript->confidence);
        $this->assertLessThan((int) config('transcription.low_confidence_threshold'), $transcript->confidence);
        $this->assertSame(TranscriptStatus::Completed, $transcript->status);
    }

    public function test_a_high_confidence_transcript_is_not_flagged(): void
    {
        $this->fakeWhisper(logprob: -0.05, noSpeech: 0.0);

        $this->uploadRecording($this->manager);

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertFalse($transcript->is_low_confidence);
        $this->assertGreaterThanOrEqual((int) config('transcription.low_confidence_threshold'), $transcript->confidence);
    }

    public function test_a_provider_failure_marks_the_transcript_failed_and_notifies_managers(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response(['error' => ['message' => 'Audio too short']], 400),
        ]);

        $this->uploadRecording($this->manager);

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertSame(TranscriptStatus::Failed, $transcript->status);
        $this->assertStringContainsString('Audio too short', (string) $transcript->failure_reason);

        Notification::assertSentTo($this->manager, TranscriptionFailedNotification::class);
        Notification::assertSentTo($this->owner, TranscriptionFailedNotification::class);
        Notification::assertNotSentTo($this->member, TranscriptionFailedNotification::class);
    }

    public function test_the_failure_notification_offers_a_manual_upload_route(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->uploadRecording($this->manager);

        Notification::assertSentTo(
            $this->manager,
            TranscriptionFailedNotification::class,
            function (TranscriptionFailedNotification $notification) {
                $payload = $notification->toArray($this->manager);

                return $payload['type'] === 'transcription_failed'
                    && str_contains($payload['message'], 'upload a transcript manually')
                    && str_contains($payload['url'], 'transcript=upload');
            },
        );
    }

    public function test_an_unconfigured_provider_fails_without_calling_out(): void
    {
        config(['transcription.openai.api_key' => null]);

        $this->uploadRecording($this->manager);

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertSame(TranscriptStatus::Failed, $transcript->status);
        $this->assertStringContainsString('not configured', (string) $transcript->failure_reason);
    }

    public function test_a_manager_can_save_a_manual_transcript_after_a_failure(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->uploadRecording($this->manager);

        $this->actingAs($this->manager)
            ->post($this->route('manual'), ['text' => 'We agreed to ship the board on Friday and unblock CI.'])
            ->assertRedirect();

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertSame(TranscriptStatus::Completed, $transcript->status);
        $this->assertSame(TranscriptSource::Manual, $transcript->source);
        $this->assertSame('We agreed to ship the board on Friday and unblock CI.', $transcript->text);
        $this->assertNull($transcript->failure_reason);
        $this->assertFalse($transcript->is_low_confidence);
    }

    public function test_a_plain_member_cannot_save_a_manual_transcript(): void
    {
        $this->actingAs($this->member)
            ->post($this->route('manual'), ['text' => 'Trying to write a transcript I should not be able to.'])
            ->assertForbidden();

        $this->assertSame(0, MeetingTranscript::query()->count());
    }

    public function test_a_failed_transcription_can_be_retried(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::sequence()
                ->push(['error' => ['message' => 'boom']], 500)
                ->push([
                    'text' => 'Recovered transcript.',
                    'language' => 'english',
                    'segments' => [['avg_logprob' => -0.1, 'no_speech_prob' => 0.01]],
                ]),
        ]);

        $this->uploadRecording($this->manager);

        $this->assertSame(TranscriptStatus::Failed, MeetingTranscript::query()->firstOrFail()->status);

        $this->actingAs($this->manager)->post($this->route('retry'))->assertRedirect();

        $transcript = MeetingTranscript::query()->firstOrFail();

        $this->assertSame(TranscriptStatus::Completed, $transcript->status);
        $this->assertSame('Recovered transcript.', $transcript->text);
        $this->assertSame(2, $transcript->attempts);
    }

    public function test_a_transcript_without_audio_cannot_be_retried(): void
    {
        MeetingTranscript::query()->create([
            'meeting_id' => $this->meeting->id,
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'status' => TranscriptStatus::Failed,
        ]);

        $this->actingAs($this->manager)->post($this->route('retry'))->assertStatus(422);
    }

    public function test_transcribing_without_audio_fails_safely(): void
    {
        $transcript = MeetingTranscript::query()->create([
            'meeting_id' => $this->meeting->id,
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'status' => TranscriptStatus::Queued,
        ]);

        app(TranscribeMeetingAction::class)->handle($transcript);

        $this->assertSame(TranscriptStatus::Failed, $transcript->refresh()->status);
        $this->assertStringContainsString('No recording', (string) $transcript->failure_reason);
    }

    public function test_re_uploading_a_recording_replaces_the_previous_file(): void
    {
        $this->fakeWhisper();

        $this->uploadRecording($this->manager);
        $first = MeetingTranscript::query()->firstOrFail()->audio_path;

        $this->uploadRecording($this->manager);
        $second = MeetingTranscript::query()->firstOrFail()->audio_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->assertSame(1, MeetingTranscript::query()->count());
    }

    public function test_a_transcript_never_crosses_workspaces(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();
        $foreignMeeting = Meeting::factory()->forProject($otherProject)->createdBy($this->owner)->create();

        $this->actingAs($this->manager)
            ->post(route('workspace.projects.meetings.transcript.manual', [
                'workspace' => $this->workspace->slug,
                'project' => $this->project->id,
                'meeting' => $foreignMeeting->id,
            ]), ['text' => 'Cross tenant transcript attempt that should never land.'])
            ->assertNotFound();

        $this->assertSame(0, MeetingTranscript::query()->count());
    }
}
