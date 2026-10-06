<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A running or paused timer, kept after it stops (state "stopped", linked to
 * the time entry it produced) so a retried Stop returns the same result.
 *
 * One active timer per person is enforced by the database:
 * active_person_id holds the person's id while the timer is running or
 * paused and NULL once it stops. It is UNIQUE, and NULLs never collide, so a
 * second active timer for the same person cannot be stored, while any number
 * of stopped ones can. This works the same on SQLite, MySQL and PostgreSQL.
 *
 * Duration = accumulated_seconds (finished segments) + now − segment_started_at
 * while running. Paused time never counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name');
            $table->string('task_title')->nullable();
            $table->string('state', 16);
            $table->unsignedBigInteger('active_person_id')->nullable()->unique();
            $table->timestamp('started_at');
            $table->timestamp('segment_started_at')->nullable();
            $table->unsignedInteger('accumulated_seconds')->default(0);
            $table->unsignedInteger('longest_segment_seconds')->default(0);
            $table->timestamp('stopped_at')->nullable();
            $table->string('timezone', 64);
            $table->boolean('billable')->default(true);
            $table->string('note', 500)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'person_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_sessions');
    }
};
