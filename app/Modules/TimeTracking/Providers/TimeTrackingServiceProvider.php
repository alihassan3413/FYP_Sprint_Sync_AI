<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Providers;

use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Policies\TimeEntryPolicy;
use App\Modules\TimeTracking\Services\EntryTimeTotals;
use App\Support\Modules\ModuleServiceProvider;

final class TimeTrackingServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'TimeTracking';

    protected array $policies = [
        TimeEntry::class => TimeEntryPolicy::class,
    ];

    /** @var array<class-string, class-string> */
    public array $bindings = [
        TimeTotals::class => EntryTimeTotals::class,
    ];
}
