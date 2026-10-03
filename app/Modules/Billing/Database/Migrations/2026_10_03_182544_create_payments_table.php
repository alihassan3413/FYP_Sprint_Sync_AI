<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received against an issued invoice. Payments are never edited or
 * deleted: a mistake is voided (voided_at, voided_by, void_reason) and a
 * correct payment recorded. Only active (not voided) payments count as paid.
 *
 * UNIQUE(workspace_id, idempotency_key): each submission of the "Record
 * payment" form carries its own key, so a double click or retry finds the
 * payment already recorded instead of creating a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->date('received_on');
            $table->string('method', 24);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['invoice_id', 'voided_at']);
            $table->index(['workspace_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
