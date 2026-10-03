<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business the workspace invoices. Not to be confused with the "client"
 * workspace role, which is a guest login (UserRole::CLIENT).
 *
 * Clients are configuration, so they follow their workspace when it is
 * deleted. Issued invoices will not: they get restrictive foreign keys, and
 * workspace deletion is blocked while they exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            // Opaque identifier used in URLs and props, so internal sequential
            // ids are never exposed. Authorization does not depend on it.
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('billing_email', 254);
            $table->json('cc_emails')->nullable();
            $table->char('currency', 3);
            $table->text('address')->nullable();
            $table->string('tax_id', 64)->nullable();
            // Relative path on the private disk. Never sent to the browser.
            $table->string('logo_path')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
