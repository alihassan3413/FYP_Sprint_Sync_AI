<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing workspaces start in their owner's timezone, the same default new
 * workspaces get. Owners without a valid timezone keep the column's UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        $valid = array_flip(DateTimeZone::listIdentifiers());

        DB::table('workspaces')
            ->join('users', 'users.id', '=', 'workspaces.owner_id')
            ->whereNotNull('users.timezone')
            ->select('workspaces.id', 'users.timezone')
            ->orderBy('workspaces.id')
            ->chunk(200, function ($rows) use ($valid) {
                foreach ($rows as $row) {
                    if (isset($valid[$row->timezone])) {
                        DB::table('workspaces')->where('id', $row->id)->update(['timezone' => $row->timezone]);
                    }
                }
            });
    }

    public function down(): void {}
};
