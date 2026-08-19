<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Meetings\Actions\QueueCompletedMeetingTranscriptions;
use Illuminate\Console\Command;

final class TranscribeCompletedMeetingsCommand extends Command
{
    protected $signature = 'meetings:transcribe-completed';

    protected $description = 'Detect meetings that have finished and queue their recordings for transcription';

    public function handle(QueueCompletedMeetingTranscriptions $action): int
    {
        $result = $action->handle();

        $this->info("Detected {$result['detected']} newly completed meeting(s); queued {$result['queued']} for transcription.");

        return self::SUCCESS;
    }
}
