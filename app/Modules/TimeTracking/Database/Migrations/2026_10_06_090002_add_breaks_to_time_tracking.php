<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid lunch and idle pauses on the timer, plus the stretches it actually ran.
 *
 * - time_sessions: lunch_started_at while on lunch; pause_reason "idle" when
 *   the desktop app paused it for inactivity.
 * - time_session_segments: each running stretch (start, end), written when it
 *   ends. Used to compare real work with the expected schedule; nothing about
 *   what the person did is recorded.
 * - time_entries: from_session_id links lunch and kept-idle entries to their
 *   timer; paid_seconds is the paid part of a lunch break;
 *   outside_schedule_seconds is tracked work outside the expected schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_sessions', function (Blueprint $table) {
            $table->timestamp('lunch_started_at')->nullable()->after('segment_started_at');
            $table->string('pause_reason', 16)->nullable()->after('lunch_started_at');
        });

        Schema::create('time_session_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('seconds');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('from_session_id')->nullable()->after('time_session_id')->constrained('time_sessions')->nullOnDelete();
            $table->unsignedInteger('paid_seconds')->nullable()->after('duration_seconds');
            $table->unsignedInteger('outside_schedule_seconds')->nullable()->after('paid_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_session_id');
            $table->dropColumn(['paid_seconds', 'outside_schedule_seconds']);
        });
        Schema::dropIfExists('time_session_segments');
        Schema::table('time_sessions', function (Blueprint $table) {
            $table->dropColumn(['lunch_started_at', 'pause_reason']);
        });
    }
};
