<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Actions;

use App\Modules\Meetings\Contracts\TranscriptionProvider;
use App\Modules\Meetings\Data\TranscriptSource;
use App\Modules\Meetings\Data\TranscriptStatus;
use App\Modules\Meetings\Exceptions\TranscriptionException;
use App\Modules\Meetings\Models\MeetingTranscript;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class TranscribeMeetingAction
{
    public function __construct(
        private readonly TranscriptionProvider $provider,
        private readonly NotifyTranscriptionFailure $notifyFailure,
    ) {}

    public function handle(MeetingTranscript $transcript): MeetingTranscript
    {
        if (! $transcript->hasAudio()) {
            return $this->fail($transcript, 'No recording has been uploaded for this meeting.');
        }

        $transcript->update([
            'status' => TranscriptStatus::Processing,
            'attempts' => $transcript->attempts + 1,
        ]);

        $disk = Storage::disk((string) config('transcription.disk'));

        if (! $disk->exists($transcript->audio_path)) {
            return $this->fail($transcript, 'The uploaded recording could no longer be found.');
        }

        try {
            $result = $this->provider->transcribe(
                $disk->path($transcript->audio_path),
                basename($transcript->audio_path),
            );
        } catch (TranscriptionException $e) {
            return $this->fail($transcript, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Meeting transcription crashed', [
                'transcript_id' => $transcript->id,
                'exception' => $e,
            ]);

            return $this->fail($transcript, 'The transcription service was unreachable.');
        }

        $threshold = (int) config('transcription.low_confidence_threshold');

        $transcript->update([
            'status' => TranscriptStatus::Completed,
            'source' => TranscriptSource::Recording,
            'text' => $result->text,
            'language' => $result->language,
            'confidence' => $result->confidence,
            'is_low_confidence' => $result->confidence !== null && $result->confidence < $threshold,
            'provider' => $result->provider,
            'model' => $result->model,
            'failure_reason' => null,
            'transcribed_at' => now(),
        ]);

        return $transcript->refresh();
    }

    private function fail(MeetingTranscript $transcript, string $reason): MeetingTranscript
    {
        $transcript->update([
            'status' => TranscriptStatus::Failed,
            'failure_reason' => $reason,
        ]);

        $this->notifyFailure->handle($transcript->refresh());

        return $transcript;
    }
}
