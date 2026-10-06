<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the timer last changed state (start, pause, resume, stop). A change
 * synced late from the desktop app (occurred_at) may not be earlier than
 * this, so offline changes replay in order and never rewrite what already
 * happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_sessions', function (Blueprint $table) {
            $table->timestamp('state_changed_at')->nullable()->after('stopped_at');
        });

        DB::table('time_sessions')->update(['state_changed_at' => DB::raw('COALESCE(stopped_at, segment_started_at, updated_at, started_at)')]);
    }

    public function down(): void
    {
        Schema::table('time_sessions', function (Blueprint $table) {
            $table->dropColumn('state_changed_at');
        });
    }
};
