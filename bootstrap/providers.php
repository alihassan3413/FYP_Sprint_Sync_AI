<?php

declare(strict_types=1);
use App\Modules\Admin\Providers\AdminServiceProvider;
use App\Modules\Analytics\Providers\AnalyticsServiceProvider;
use App\Modules\Archive\Providers\ArchiveServiceProvider;
use App\Modules\Assistant\Providers\AssistantServiceProvider;
use App\Modules\Attachments\Providers\AttachmentsServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
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
    AnalyticsServiceProvider::class,
    AttachmentsServiceProvider::class,
    AuditServiceProvider::class,
    AdminServiceProvider::class,
];
