<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracked time on hourly invoices.
 *
 * - billing_plan_projects: which projects' time belongs to a recurring
 *   invoice (projects are not tied to clients, so the owner says which).
 * - invoice_lines: what time tracking recorded for the period (tracked_seconds
 *   and its centi-hours) next to what the client is billed (quantity_centi),
 *   with the reason and who set it when the two differ. Both are part of the
 *   snapshot and freeze with the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plan_projects', function (Blueprint $table) {
            $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['billing_plan_id', 'project_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->json('time_project_ids')->nullable()->after('bill_from');
            $table->timestamp('tracked_at')->nullable()->after('time_project_ids');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedInteger('tracked_seconds')->nullable()->after('quantity_centi');
            $table->unsignedInteger('tracked_quantity_centi')->nullable()->after('tracked_seconds');
            $table->string('quantity_reason', 300)->nullable()->after('tracked_quantity_centi');
            $table->foreignId('quantity_set_by')->nullable()->after('quantity_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('quantity_set_at')->nullable()->after('quantity_set_by');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quantity_set_by');
            $table->dropColumn(['tracked_seconds', 'tracked_quantity_centi', 'quantity_reason', 'quantity_set_at']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['time_project_ids', 'tracked_at']);
        });
        Schema::dropIfExists('billing_plan_projects');
    }
};
