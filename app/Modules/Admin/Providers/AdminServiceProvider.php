<?php

declare(strict_types=1);

namespace App\Modules\Admin\Providers;

use App\Support\Modules\ModuleServiceProvider;

final class AdminServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Admin';
}
