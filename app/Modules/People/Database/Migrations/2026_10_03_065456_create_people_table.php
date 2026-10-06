<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Someone who works for the workspace. Not the same as a SprintSync user: a
 * person may have no login at all, and may optionally be linked to one.
 *
 * Pay and client billing are deliberately not stored here; they are separate
 * concepts added later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 254)->nullable();
            $table->string('title', 120)->nullable();
            $table->timestamps();

            // One user is one person per workspace. NULLs (no login) are not constrained.
            $table->unique(['workspace_id', 'user_id']);
            $table->index(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
