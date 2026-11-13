<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What anyone holding a project's share link can do. Viewing needs no account;
 * adding tasks always needs one, so every task has a real author.
 */
#[TypeScript]
enum BoardShareAccess: string
{
    case View = 'view';
    case Add = 'add';
}
