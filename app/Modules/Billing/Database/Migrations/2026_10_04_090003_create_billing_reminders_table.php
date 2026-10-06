<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per owner reminder ever sent ("invoice 12 due-day", "plan 3
 * upcoming 2026-11"). The unique key is what makes reminders exactly-once:
 * the row and the notification are written in one transaction, and a second
 * scheduler run cannot insert the same key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_key', 120)->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reminders');
    }
};
