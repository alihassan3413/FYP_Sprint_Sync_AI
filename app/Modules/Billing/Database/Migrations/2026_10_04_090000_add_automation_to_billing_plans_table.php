<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * automation_enabled_at is the recurring automation's activation boundary:
 * only months whose generation date falls strictly after it (in the
 * workspace's calendar) are generated and processed automatically. It is set
 * when a plan is created, when it is resumed, and once for existing plans by
 * the next migration, so turning automation on never backfills history.
 * resumed_at lets the plan explain a month that was skipped while paused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->timestamp('automation_enabled_at')->nullable()->after('paused_at');
            $table->timestamp('resumed_at')->nullable()->after('automation_enabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_plans', function (Blueprint $table) {
            $table->dropColumn(['automation_enabled_at', 'resumed_at']);
        });
    }
};
