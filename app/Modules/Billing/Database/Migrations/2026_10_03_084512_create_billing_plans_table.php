<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring invoice ("billing plan") for one client: what to bill, when,
 * and how it is delivered. Plans are configuration only; issued invoices will
 * snapshot everything they print, so editing a plan never changes history.
 *
 * Two days per month, both in the workspace's timezone:
 * - generation_day: the draft is created (and hourly plans gather hours).
 * - send_day: the invoice is planned to be issued and sent.
 * Fixed plans use the same day for both; hourly plans generate on the 1st and
 * send later (the 3rd by default) so hours can be checked in between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('currency', 3);
            $table->string('pricing_mode', 16);
            $table->unsignedTinyInteger('generation_day');
            $table->unsignedTinyInteger('send_day');
            $table->string('billing_period', 24);
            $table->unsignedSmallInteger('due_in_days');
            $table->string('delivery_mode', 32);
            $table->unsignedTinyInteger('reminder_days_before')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'name']);
            $table->index(['workspace_id', 'paused_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plans');
    }
};
