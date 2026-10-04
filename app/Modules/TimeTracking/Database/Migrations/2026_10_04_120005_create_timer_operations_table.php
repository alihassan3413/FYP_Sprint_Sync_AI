<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every timer change (start, pause, resume, stop) carries a client-generated
 * operation key. It is stored in the same transaction as the change, and
 * UNIQUE, so a retried request (a reconnecting desktop app, a double click)
 * returns the result of the first one instead of acting again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timer_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('operation_key', 64);
            $table->string('action', 16);
            $table->foreignId('time_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['workspace_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timer_operations');
    }
};
