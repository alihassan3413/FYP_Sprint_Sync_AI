<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - approved_automatically: approved by SprintSync (approved_by stays null,
 *   because no person approved it), not by a person.
 * - automation_checked_at: when automatic sending made its one decision for
 *   this invoice. Set once (conditional update), so the decision is never made
 *   twice and late data never triggers a surprise send.
 * - automation_hold: why it was held for the owner instead of being sent
 *   automatically: a list of {code, message}. Kept so the reason survives
 *   refreshes and scheduler runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('approved_automatically')->default(false)->after('approved_by');
            $table->timestamp('automation_checked_at')->nullable()->after('issued_at');
            $table->json('automation_hold')->nullable()->after('automation_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['approved_automatically', 'automation_checked_at', 'automation_hold']);
        });
    }
};
