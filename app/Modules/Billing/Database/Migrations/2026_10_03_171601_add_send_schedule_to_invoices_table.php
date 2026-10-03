<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval and issuing are separate moments:
 * - send_after: an approved invoice waits until then (the cancel window), and
 *   the delivery step picks up approved invoices whose send_after has passed.
 * - issued_at: when it was issued; the business number is assigned then, and
 *   from that moment the invoice can never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('send_after')->nullable()->after('approved_by');
            $table->timestamp('issued_at')->nullable()->after('send_after');

            $table->index(['status', 'send_after']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'send_after']);
            $table->dropColumn(['send_after', 'issued_at']);
        });
    }
};
