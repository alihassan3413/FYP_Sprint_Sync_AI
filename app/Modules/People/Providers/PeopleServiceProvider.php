<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Modules\People\Models\Person;
use App\Modules\People\Models\TeamImport;
use App\Modules\People\Policies\PersonPolicy;
use App\Modules\People\Policies\TeamImportPolicy;
use App\Support\Modules\ModuleServiceProvider;

final class PeopleServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'People';

    protected array $policies = [
        Person::class => PersonPolicy::class,
        TeamImport::class => TeamImportPolicy::class,
    ];
}
