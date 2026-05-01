<?php

namespace App\Modules\Workspace\Data;

use App\UserRole;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
final class WorkspaceInvitationData extends Data
{
    public function __construct(
        #[TypeScriptType('string')]
        public string $email,

        #[TypeScriptType("'admin' | 'member'")]
        public UserRole $role,
    ) {}
}
