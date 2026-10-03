<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fee, tax or discount as configured when the invoice was generated (value:
 * basis points or minor units) and what it came to (amount_minor, signed:
 * discounts are negative).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 16);
            $table->string('type', 16);
            $table->string('label', 80);
            $table->unsignedBigInteger('value');
            $table->bigInteger('amount_minor')->default(0);

            $table->unique(['invoice_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_adjustments');
    }
};
