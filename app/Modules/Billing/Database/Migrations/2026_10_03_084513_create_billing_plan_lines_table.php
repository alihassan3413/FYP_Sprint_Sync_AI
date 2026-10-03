<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a recurring invoice: a team member or a plain item (rent, a
 * retainer). Fixed plans bill unit_price_minor once a month; hourly plans bill
 * it per hour, with the hours supplied per billing period, never stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('description', 160);
            $table->string('role_label', 120)->nullable();
            $table->unsignedBigInteger('unit_price_minor');

            $table->unique(['billing_plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plan_lines');
    }
};
