<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum LeaveType: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
}
