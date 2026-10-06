<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency reporting, never by adding currencies together.
 *
 * - workspace_finance_settings: the reporting currency (profit is shown in it).
 * - exchange_rates: "1 {base} = {rate} {quote}" from a given date, entered by
 *   the owner (or, later, a provider). The rate is an exact decimal string
 *   (never a float). Rows are append-only: a correction is a newer row.
 * - fx_snapshots: the rate actually used to convert one record into one
 *   currency, captured the first time it was needed. Reports reuse it, so a
 *   rate entered later never changes past results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_finance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reporting_currency', 3);
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->string('rate', 40);
            $table->date('rate_date');
            $table->string('source', 20)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'base_currency', 'quote_currency', 'rate_date']);
        });

        Schema::create('fx_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->string('rate', 40);
            $table->date('rate_date');
            $table->string('source', 20);
            $table->foreignId('exchange_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('captured_at');

            $table->unique(['subject_type', 'subject_id', 'to_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_snapshots');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('workspace_finance_settings');
    }
};
