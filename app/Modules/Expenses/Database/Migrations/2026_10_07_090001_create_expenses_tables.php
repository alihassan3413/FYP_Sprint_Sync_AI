<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business expenses, in their own currency (integer minor units, never
 * converted in place).
 *
 * - recurring_expenses: "Office rent, PKR 300,000, monthly on the 1st".
 *   Editing it only affects months not yet recorded.
 * - expenses: one-off expenses and each month's occurrence of a recurring
 *   one (a snapshot: later edits never change it).
 *   UNIQUE(recurring_expense_id, period_start) means one occurrence per
 *   month however often the scheduler runs. Mistakes are voided (kept on
 *   record), never deleted.
 *
 * client_id / project_id are optional links for later per-client profit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('description', 160);
            $table->string('vendor', 120)->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedTinyInteger('day_of_month');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('description', 160);
            $table->string('vendor', 120)->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->date('expense_date');
            $table->foreignId('recurring_expense_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['recurring_expense_id', 'period_start']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('recurring_expenses');
    }
};
