<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What one person is carrying on a project. `share_percentage` is of the open
 * work only: finished work says who was busy, not who is busy.
 */
#[TypeScript]
final class WorkloadEntryData extends Data
{
    public function __construct(
        public ?int $user_id,
        public string $name,
        public int $open_tasks,
        public int $overdue_tasks,
        public int $completed_tasks,
        public int $share_percentage,
    ) {}
}
