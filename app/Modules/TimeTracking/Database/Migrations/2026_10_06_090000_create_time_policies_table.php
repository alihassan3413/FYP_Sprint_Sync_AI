<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's time rules, one row per version. Rows are never edited: saving
 * adds a new version that applies from effective_from, so October's time is
 * always explained by October's rules. No row means every feature is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_policies', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->timestamp('effective_from');
            $table->boolean('idle_enabled')->default(false);
            $table->unsignedInteger('idle_seconds')->nullable();
            $table->unsignedInteger('weekly_target_seconds')->nullable();
            $table->boolean('lunch_enabled')->default(false);
            $table->unsignedInteger('lunch_allowance_seconds')->nullable();
            $table->string('overtime_mode', 20)->default('track_only');
            $table->boolean('integrity_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_policies');
    }
};
