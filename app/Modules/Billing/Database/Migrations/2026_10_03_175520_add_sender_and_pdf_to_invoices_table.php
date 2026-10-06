<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - bill_from: the sender snapshot, copied from the workspace's invoicing
 *   details. Null on invoices prepared before those details existed; it is
 *   filled when the invoice is approved, and issuing requires it.
 * - pdf_path: the official PDF, written once after the invoice is issued at a
 *   path that only depends on the invoice, and never regenerated after that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('bill_from')->nullable()->after('bill_to');
            $table->string('pdf_path')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['bill_from', 'pdf_path']);
        });
    }
};
