<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

use App\Modules\Audit\Models\AuditLog;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AuditLogEntryData extends Data
{
    public function __construct(
        public int $id,
        public ?string $actor_name,
        public ?string $actor_avatar_url,
        public string $action_label,
        public string $category,
        public string $description,
        public ?string $project_name,
        public string $created_at,
    ) {}

    public static function fromModel(AuditLog $log): self
    {
        $action = AuditAction::tryFrom($log->action);

        return new self(
            id: $log->id,
            actor_name: $log->user?->name,
            actor_avatar_url: $log->user?->avatar_url,
            action_label: $action?->label() ?? $log->action,
            category: $action?->category() ?? 'Other',
            description: $log->description,
            project_name: $log->project?->name,
            created_at: $log->created_at->toIso8601String(),
        );
    }
}
