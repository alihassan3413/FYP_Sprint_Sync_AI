<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Activation boundary for plans that existed before recurring automation:
 * "now". Their past and current months are left exactly as they are (nothing
 * is generated, approved or emailed for them); automation starts with the
 * next generation date after this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('billing_plans')->whereNull('automation_enabled_at')->update(['automation_enabled_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: the column itself is dropped by the previous migration.
    }
};
