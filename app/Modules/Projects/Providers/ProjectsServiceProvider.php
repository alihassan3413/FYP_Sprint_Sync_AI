<?php

declare(strict_types=1);

namespace App\Modules\Projects\Providers;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Policies\SprintPolicy;
use App\Support\Modules\ModuleServiceProvider;

final class ProjectsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Projects';

    protected array $policies = [
        Project::class => ProjectPolicy::class,
        Sprint::class => SprintPolicy::class,
    ];
}
