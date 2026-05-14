<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

use App\Modules\Workspace\Models\WorkspaceRole;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
class WorkspaceRoleData extends Data
{
    public function __construct(
        public ?int $id,

        public string $name,

        public string $slug,

        #[TypeScriptType('Record<string, boolean> | null')]
        public ?array $permissions,

        public int $workspace_id,
    ) {}

    public static function fromModel(WorkspaceRole $role): self
    {
        return new self(
            id: $role->id,
            name: $role->name,
            slug: $role->slug,
            permissions: $role->permissions,
            workspace_id: $role->workspace_id,
        );
    }
}
