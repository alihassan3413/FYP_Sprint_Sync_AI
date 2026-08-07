<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Providers;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Policies\MeetingPolicy;
use App\Support\Modules\ModuleServiceProvider;

final class MeetingsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Meetings';

    protected array $policies = [
        Meeting::class => MeetingPolicy::class,
    ];
}
