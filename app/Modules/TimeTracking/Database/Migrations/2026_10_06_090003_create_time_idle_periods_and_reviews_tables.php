<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - time_idle_periods: an automatic idle pause, from the last activity until
 *   the timer resumed, and what the person decided (keep as work, with a
 *   reason, or not). The detection itself is never erased.
 * - time_reviews: things for the owner to look at (lunch longer than the paid
 *   allowance, idle time kept as work, an unusual activity pattern), with the
 *   owner's decision. Unresolved activity reviews hold automatic invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_idle_periods', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('time_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('detected_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('threshold_seconds');
            $table->string('decision', 16)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->foreignId('kept_entry_id')->nullable()->constrained('time_entries')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'person_id', 'decision']);
        });

        Schema::create('time_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('severity', 16)->default('info');
            $table->date('work_date');
            $table->string('subject_key', 120)->nullable();
            $table->unsignedInteger('seconds')->nullable();
            $table->string('summary', 300);
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('decision', 24)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'subject_key']);
            $table->index(['workspace_id', 'status', 'kind']);
            $table->index(['workspace_id', 'person_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_reviews');
        Schema::dropIfExists('time_idle_periods');
    }
};
