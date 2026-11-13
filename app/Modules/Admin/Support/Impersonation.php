<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use Illuminate\Http\Request;

final class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public static function impersonatorId(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);

        return is_int($id) ? $id : null;
    }

    public static function active(Request $request): bool
    {
        return self::impersonatorId($request) !== null;
    }
}
