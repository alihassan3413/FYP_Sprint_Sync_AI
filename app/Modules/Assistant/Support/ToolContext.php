<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;

final readonly class ToolContext
{
    public function __construct(
        public User $user,
        public ?Workspace $workspace = null,
    ) {}

    public function hasWorkspace(): bool
    {
        return $this->workspace !== null;
    }
}
