<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Policies\AuditLogPolicy;
use App\Support\Modules\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Audit';

    protected array $policies = [
        AuditLog::class => AuditLogPolicy::class,
    ];
}
