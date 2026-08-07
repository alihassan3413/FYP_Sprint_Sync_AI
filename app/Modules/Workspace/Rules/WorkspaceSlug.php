<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class WorkspaceSlug implements ValidationRule
{
    public const RESERVED = [
        'admin',
        'api',
        'app',
        'assistant',
        'dashboard',
        'invitations',
        'login',
        'logout',
        'register',
        'settings',
        'up',
        'workspace',
        'workspaces',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            $fail('The :attribute may only contain lowercase letters, numbers and single hyphens.');

            return;
        }

        if (in_array($value, self::RESERVED, true)) {
            $fail('The :attribute is reserved. Please choose another one.');
        }
    }
}
