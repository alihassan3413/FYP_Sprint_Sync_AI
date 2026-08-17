<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

use App\Modules\Meetings\Models\Meeting;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DashboardMeetingData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public int $project_id,
        public string $project_name,
        public string $scheduled_at,
        public int $duration_minutes,
        public ?string $join_url,
        public bool $is_past,
        public string $url,
    ) {}

    public static function fromModel(Meeting $meeting, bool $isPast, string $url): self
    {
        return new self(
            id: $meeting->id,
            title: $meeting->title,
            project_id: $meeting->project_id,
            project_name: $meeting->project->name,
            scheduled_at: $meeting->scheduled_at->toIso8601String(),
            duration_minutes: $meeting->duration_minutes,
            join_url: $meeting->hasValidJoinLink() ? $meeting->meeting_link : null,
            is_past: $isPast,
            url: $url,
        );
    }
}
