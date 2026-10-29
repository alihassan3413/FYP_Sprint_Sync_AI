<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum InvoiceSource: string
{
    case Plan = 'plan';
    case Manual = 'manual';
}
