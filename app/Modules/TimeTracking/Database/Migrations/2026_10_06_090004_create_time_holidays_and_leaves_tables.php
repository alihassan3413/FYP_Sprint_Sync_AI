<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid holidays and leave are credits, not tracked time: they count toward
 * paid/expected hours and never toward client billing, and no timer entries
 * are invented for them.
 *
 * - time_holidays: one business date; everyone (no rows in
 *   time_holiday_people) or the people listed.
 * - time_leaves: one person, a date range, paid or unpaid; paid leave credits
 *   credit_seconds_per_day on each working day of the range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_holidays', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 120);
            $table->unsignedInteger('credit_seconds');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'date']);
        });

        Schema::create('time_holiday_people', function (Blueprint $table) {
            $table->foreignId('time_holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->primary(['time_holiday_id', 'person_id']);
        });

        Schema::create('time_leaves', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type', 10);
            $table->unsignedInteger('credit_seconds_per_day');
            $table->string('note', 300)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'person_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_leaves');
        Schema::dropIfExists('time_holiday_people');
        Schema::dropIfExists('time_holidays');
    }
};
