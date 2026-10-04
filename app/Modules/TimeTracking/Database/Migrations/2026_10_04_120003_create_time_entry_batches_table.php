<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One "Add previous hours" submission: the shared project, period and reason
 * for its rows. UNIQUE(workspace_id, idempotency_key) makes a double submit
 * return the same batch instead of saving the hours twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entry_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 64);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('note', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_batches');
    }
};
