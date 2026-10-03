<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace's own details as they appear on its invoices (the sender).
 * One row per workspace. Invoices copy these into their bill_from snapshot;
 * editing them later never changes an existing invoice.
 *
 * logo_path points at a content-addressed file (named by its SHA-256), so a
 * path always means exactly one image and old invoices keep theirs when the
 * logo is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoicing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name', 120);
            $table->string('legal_name', 160)->nullable();
            $table->string('billing_email', 254);
            $table->string('phone', 40)->nullable();
            $table->string('address_line1', 160);
            $table->string('address_line2', 160)->nullable();
            $table->string('city', 80);
            $table->string('region', 80)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 80);
            $table->string('tax_id', 64)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoicing_profiles');
    }
};
