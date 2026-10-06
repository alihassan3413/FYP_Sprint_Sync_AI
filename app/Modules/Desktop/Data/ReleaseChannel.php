<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Data;

/**
 * stable: public downloads. uat: test builds for signed-in people only,
 * unsigned until code signing is set up.
 */
enum ReleaseChannel: string
{
    case Stable = 'stable';
    case Uat = 'uat';
}
