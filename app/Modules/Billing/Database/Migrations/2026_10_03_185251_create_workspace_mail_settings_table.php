<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace's own email server, used for every client-facing invoice
 * email. There is no fallback to SprintSync's mail: without this row, invoices
 * are not emailed.
 *
 * password is stored with Laravel's encrypted cast (APP_KEY), never returned
 * to the browser, never logged, never queued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('host', 255);
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 8);
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();
            $table->string('from_email', 254);
            $table->string('from_name', 120);
            $table->string('reply_to_email', 254)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_error', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_mail_settings');
    }
};
