<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance schedules (billing periods, generation, reminders, auto-send,
 * overdue) are business dates, so they are calculated in the workspace's
 * zone rather than in UTC or in whichever user happens to be looking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
