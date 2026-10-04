<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorded time, in integer seconds. Three sources:
 *
 * - tracked: from a timer. started_at/ended_at are real; one entry per timer
 *   (UNIQUE time_session_id), so a retried Stop never adds time twice.
 * - manual: a person's own time for one day (no invented start/end times).
 * - adjustment: the owner's signed correction or historical total for a
 *   period ("48h between Oct 1 and Oct 9"). Never fake start/end times.
 *
 * period_start/period_end are business dates in the entry's timezone (the
 * workspace's): the day a timer started, the manual day, or the adjustment's
 * period. Totals for a date range use period_start. Original tracked entries
 * are never edited; corrections are adjustments (adjusts_entry_id).
 * Projects and tasks may be deleted later, so their names are snapshotted and
 * the links become NULL rather than deleting history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name');
            $table->string('task_title')->nullable();
            $table->string('source', 16);
            $table->foreignId('time_session_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('time_entry_batches')->nullOnDelete();
            $table->foreignId('adjusts_entry_id')->nullable()->constrained('time_entries')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_seconds');
            $table->unsignedInteger('longest_segment_seconds')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('timezone', 64);
            $table->boolean('billable')->default(true);
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'person_id', 'period_start']);
            $table->index(['workspace_id', 'project_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
