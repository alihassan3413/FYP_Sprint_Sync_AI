<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Providers;

use App\Modules\Attachments\Console\Commands\PruneAttachmentsCommand;
use App\Modules\Attachments\Models\Attachment;
use App\Modules\Attachments\Policies\AttachmentPolicy;
use App\Support\Modules\ModuleServiceProvider;

final class AttachmentsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Attachments';

    protected array $policies = [
        Attachment::class => AttachmentPolicy::class,
    ];

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneAttachmentsCommand::class]);
        }
    }
}
