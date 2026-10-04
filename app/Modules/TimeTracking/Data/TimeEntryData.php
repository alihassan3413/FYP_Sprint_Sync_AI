<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use App\Modules\TimeTracking\Models\TimeEntry;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One recorded entry for the time page. Durations are integer seconds; the
 * browser formats them. Times are ISO strings, dates are business dates.
 */
#[TypeScript]
final class TimeEntryData extends Data
{
    public function __construct(
        public string $public_id,
        #[LiteralTypeScriptType("'tracked' | 'manual' | 'adjustment'")]
        public string $source,
        public string $source_label,
        public string $person_name,
        public string $project_name,
        public ?string $task_title,
        public ?string $started_at,
        public ?string $ended_at,
        public string $period_start,
        public string $period_end,
        public int $duration_seconds,
        public bool $billable,
        public ?string $note,
        /** Who added it, only when that wasn't the person themselves (the owner, for example). */
        public ?string $created_by,
        public ?string $adjusts_entry_public_id,
        public bool $voided,
        public ?string $void_reason,
    ) {}

    public static function fromModel(TimeEntry $entry): self
    {
        return new self(
            public_id: $entry->public_id,
            source: $entry->source->value,
            source_label: $entry->source->label(),
            person_name: $entry->person->name,
            project_name: $entry->project_name,
            task_title: $entry->task_title,
            started_at: $entry->started_at?->toIso8601String(),
            ended_at: $entry->ended_at?->toIso8601String(),
            period_start: $entry->period_start->toDateString(),
            period_end: $entry->period_end->toDateString(),
            duration_seconds: $entry->duration_seconds,
            billable: $entry->billable,
            note: $entry->note,
            created_by: $entry->created_by !== null && $entry->created_by !== $entry->person->user_id ? $entry->creator?->name : null,
            adjusts_entry_public_id: $entry->adjusts?->public_id,
            voided: $entry->isVoided(),
            void_reason: $entry->void_reason,
        );
    }
}
