<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PlatformMetricsData extends Data
{
    /**
     * @param  array<int, SignupPointData>  $signups
     */
    public function __construct(
        public int $users_total,
        public int $users_verified,
        public int $users_new_30d,
        public int $workspaces_total,
        public int $workspaces_active,
        public int $projects_total,
        public int $tasks_total,
        public int $tasks_completed,
        public int $sprints_total,
        public int $meetings_total,
        public array $signups,
    ) {}
}
