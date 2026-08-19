<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Jobs;

use App\Modules\Meetings\Actions\TranscribeMeetingAction;
use App\Modules\Meetings\Models\MeetingTranscript;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class TranscribeMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $transcriptId) {}

    public function handle(TranscribeMeetingAction $action): void
    {
        $transcript = MeetingTranscript::query()->find($this->transcriptId);

        if ($transcript === null) {
            return;
        }

        $action->handle($transcript);
    }
}
