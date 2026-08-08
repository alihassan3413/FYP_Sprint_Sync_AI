<?php

declare(strict_types=1);

namespace App\Modules\Archive\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ArchiveRecordData extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public ?string $subtitle,
        public int $project_id,
        public string $project_name,
        public ?int $assignee_id,
        public ?string $assignee_name,
        public string $occurred_at,
        public string $url,
    ) {}

    public static function fromRow(object $row, string $url): self
    {
        return new self(
            id: "{$row->type}-{$row->id}",
            type: $row->type,
            title: $row->title,
            subtitle: $row->subtitle,
            project_id: (int) $row->project_id,
            project_name: $row->project_name,
            assignee_id: $row->assignee_id !== null ? (int) $row->assignee_id : null,
            assignee_name: $row->assignee_name,
            occurred_at: $row->occurred_at,
            url: $url,
        );
    }
}
