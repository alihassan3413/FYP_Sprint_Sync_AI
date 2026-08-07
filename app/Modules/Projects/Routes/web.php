<?php

declare(strict_types=1);

use App\Support\Routing\TenantRoute;

TenantRoute::prefixed('projects', 'workspace.projects.', function () {
    //
});
