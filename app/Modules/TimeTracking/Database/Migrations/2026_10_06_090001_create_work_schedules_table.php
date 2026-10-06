<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expected working hours: the workspace default (person_id NULL) or one
 * person's own. Append-only like policies (effective_from). A schedule never
 * creates hours; it is only compared with real timer times.
 * end_time at or before start_time means the shift ends the next day
 * (18:00–02:00).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->json('days')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('timezone', 64);
            $table->timestamp('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'person_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
