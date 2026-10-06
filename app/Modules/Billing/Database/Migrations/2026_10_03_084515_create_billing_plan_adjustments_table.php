<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fee, tax or discount applied to a recurring invoice's subtotal. value is
 * basis points for a percentage (2% = 200) or minor units for a fixed amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plan_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 16);
            $table->string('type', 16);
            $table->string('label', 80);
            $table->unsignedBigInteger('value');

            $table->unique(['billing_plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plan_adjustments');
    }
};
