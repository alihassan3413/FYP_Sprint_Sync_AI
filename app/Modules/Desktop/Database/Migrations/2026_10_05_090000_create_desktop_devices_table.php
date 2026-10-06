<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed-in copy of SprintSync Desktop ("Ali's MacBook Pro · macOS").
 * Only a SHA-256 hash of its token is stored, so the database never holds a
 * usable token. Revoking sets revoked_at; nothing identifying the hardware
 * (serials, MAC addresses) is collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_devices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('platform', 20);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_devices');
    }
};
