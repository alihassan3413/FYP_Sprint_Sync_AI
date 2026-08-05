<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

use App\Modules\Workspace\Models\WorkspaceRole;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
final class WorkspaceRoleData extends Data
{
    /**
     * @param  array<string, bool>|null  $permissions
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $slug = null,
        #[TypeScriptType('Record<string, boolean> | null')]
        public ?array $permissions = null,
        public ?int $workspace_id = null,
        public ?int $member_count = null,
    ) {}

    public static function fromModel(WorkspaceRole $role): self
    {
        return new self(
            id: $role->id,
            name: $role->name,
            slug: $role->slug,
            permissions: $role->permissions,
            workspace_id: $role->workspace_id,
            member_count: $role->members_count ?? null,
        );
    }
}
