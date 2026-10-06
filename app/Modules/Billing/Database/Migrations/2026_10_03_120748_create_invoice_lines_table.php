<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One printed line, fully self-describing. person_id is only a reference back
 * to the team member; description and role_label are the snapshot. Quantity is
 * in hundredths (a fixed line is 100; 160.5 hours is 16050) and stays null on
 * an hourly line until its hours are entered. amount_minor is null until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('description', 160);
            $table->string('role_label', 120)->nullable();
            $table->unsignedInteger('quantity_centi')->nullable();
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('amount_minor')->nullable();

            $table->unique(['invoice_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
