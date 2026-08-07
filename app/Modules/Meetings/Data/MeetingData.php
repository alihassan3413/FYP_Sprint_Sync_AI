<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Data;

use App\Modules\Meetings\Models\Meeting;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MeetingData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $description,
        public string $scheduled_at,
        public int $duration_minutes,
        public ?string $meeting_link,
        public int $project_id,
        public int $workspace_id,
        public int $created_by,
        public ?string $creator_name,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Meeting $meeting): self
    {
        return new self(
            id: $meeting->id,
            title: $meeting->title,
            description: $meeting->description,
            scheduled_at: $meeting->scheduled_at->toIso8601String(),
            duration_minutes: $meeting->duration_minutes,
            meeting_link: $meeting->meeting_link,
            project_id: $meeting->project_id,
            workspace_id: $meeting->workspace_id,
            created_by: $meeting->created_by,
            creator_name: $meeting->creator?->name,
            created_at: $meeting->created_at->toIso8601String(),
            updated_at: $meeting->updated_at->toIso8601String(),
        );
    }
}
