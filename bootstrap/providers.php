<?php

declare(strict_types=1);
use App\Modules\Archive\Providers\ArchiveServiceProvider;
use App\Modules\Assistant\Providers\AssistantServiceProvider;
use App\Modules\Meetings\Providers\MeetingsServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Modules\Tasks\Providers\TasksServiceProvider;
use App\Modules\Teams\Providers\TeamsServiceProvider;
use App\Modules\Workspace\Providers\WorkspaceServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    WorkspaceServiceProvider::class,
    TeamsServiceProvider::class,
    ProjectsServiceProvider::class,
    TasksServiceProvider::class,
    MeetingsServiceProvider::class,
    AssistantServiceProvider::class,
    ArchiveServiceProvider::class,
];
