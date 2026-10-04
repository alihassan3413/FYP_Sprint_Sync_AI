<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One logical email delivery per invoice (UNIQUE(invoice_id, channel)):
 * retries reuse this row and bump `attempts` instead of creating new ones.
 *
 * Who it goes to, the subject and the visible From are snapshotted when the
 * delivery is created (from the invoice's bill_to / bill_from and the mail
 * settings' From identity); SMTP credentials never are.
 *
 * status: pending → sending → sent | failed | needs_attention. Only a
 * conditional update moves it to `sending`, so two workers can never send
 * the same invoice at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('status', 24);
            $table->string('recipient', 254);
            $table->json('cc')->nullable();
            $table->string('subject', 255);
            $table->string('from_email', 254);
            $table->string('from_name', 120);
            $table->string('reply_to_email', 254)->nullable();
            $table->string('message_id', 255);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 200)->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'channel']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_deliveries');
    }
};
