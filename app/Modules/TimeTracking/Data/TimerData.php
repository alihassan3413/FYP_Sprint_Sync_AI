<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A timer as a client sees it (web page now, the desktop app later). Enough
 * to show live elapsed time after a restart: elapsed = accumulated_seconds +
 * (server_time − running_since) while running. Public ids only.
 */
#[TypeScript]
final class TimerData extends Data
{
    public function __construct(
        public string $public_id,
        #[LiteralTypeScriptType("'running' | 'paused' | 'stopped'")]
        public string $state,
        #[LiteralTypeScriptType('{ public_id: string | null; name: string }')]
        public array $project,
        #[LiteralTypeScriptType('{ public_id: string | null; title: string } | null')]
        public ?array $task,
        public string $started_at,
        public ?string $running_since,
        public int $accumulated_seconds,
        public int $elapsed_seconds,
        public int $longest_segment_seconds,
        public bool $billable,
        public ?string $note,
        public string $server_time,
        public ?string $entry_public_id,
    ) {}

    public static function fromSession(TimeSession $session, CarbonInterface $now): self
    {
        $session->loadMissing(['project:id,public_id', 'task:id,public_id']);

        return new self(
            public_id: $session->public_id,
            state: $session->state->value,
            project: ['public_id' => $session->project?->public_id, 'name' => $session->project_name],
            task: $session->task_title === null ? null : ['public_id' => $session->task?->public_id, 'title' => $session->task_title],
            started_at: $session->started_at->toIso8601String(),
            running_since: $session->segment_started_at?->toIso8601String(),
            accumulated_seconds: $session->accumulated_seconds,
            elapsed_seconds: $session->elapsedSeconds($now),
            longest_segment_seconds: max($session->longest_segment_seconds, $session->runningSeconds($now)),
            billable: $session->billable,
            note: $session->note,
            server_time: $now->toIso8601String(),
            entry_public_id: $session->relationLoaded('entry') ? $session->entry?->public_id : null,
        );
    }
}
