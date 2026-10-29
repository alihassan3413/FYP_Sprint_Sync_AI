<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('source', 16)->default('plan')->after('billing_plan_id');
            $table->foreignId('client_id')->nullable()->change();
            $table->text('notes')->nullable()->after('bill_from');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->index(['workspace_id', 'source']);
            $table->unique(['workspace_id', 'idempotency_key']);
        });

        Schema::table('invoice_deliveries', function (Blueprint $table) {
            $table->json('recipients')->nullable()->after('recipient');
            $table->json('bcc')->nullable()->after('cc');
            $table->text('message')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_deliveries', function (Blueprint $table) {
            $table->dropColumn(['recipients', 'bcc', 'message']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'idempotency_key']);
            $table->dropIndex(['workspace_id', 'source']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['source', 'notes', 'voided_at', 'void_reason', 'idempotency_key']);
        });
    }
};
