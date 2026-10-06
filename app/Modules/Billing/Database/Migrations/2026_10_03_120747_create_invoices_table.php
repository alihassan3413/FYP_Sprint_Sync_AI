<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice is a self-contained snapshot. Everything it prints (lines, rates,
 * adjustments, who it is billed to) is copied in when it is generated, so
 * later changes to the recurring invoice, the team or the client never change
 * it. It references the plan and client only to know where it came from.
 *
 * UNIQUE(billing_plan_id, period_start) makes generation idempotent: one
 * invoice per recurring invoice per month, however often generation runs.
 * UNIQUE(workspace_id, number) makes business numbers unique; drafts have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 32)->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('version')->default(1);

            $table->string('title', 120);
            $table->string('pricing_mode', 16);
            $table->string('delivery_mode', 32);
            $table->string('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('generated_on');
            $table->date('planned_send_on');
            $table->unsignedSmallInteger('due_in_days');
            $table->json('bill_to');

            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('adjustments_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);

            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['billing_plan_id', 'period_start']);
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
