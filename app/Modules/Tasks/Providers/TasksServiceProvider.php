<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Providers;

use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Tasks\Policies\BoardColumnPolicy;
use App\Modules\Tasks\Policies\TaskCommentPolicy;
use App\Modules\Tasks\Policies\TaskPolicy;
use App\Support\Modules\ModuleServiceProvider;

final class TasksServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Tasks';

    protected array $policies = [
        Task::class => TaskPolicy::class,
        BoardColumn::class => BoardColumnPolicy::class,
        TaskComment::class => TaskCommentPolicy::class,
    ];
}
