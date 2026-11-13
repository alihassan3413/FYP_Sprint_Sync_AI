<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trello-style board sharing:
 * - projects.share_access: null (off), 'view' or 'add'; share_token is the
 *   secret in the link and is replaced when the link is regenerated.
 * - project_users.can_add_tasks: a per-board grant for guests who joined from
 *   the link or had their access request approved.
 * - project_access_requests: one row per person per board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique();
            $table->string('share_access', 8)->nullable();
        });

        Schema::table('project_users', function (Blueprint $table) {
            $table->boolean('can_add_tasks')->default(false);
        });

        Schema::create('project_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_access_requests');

        Schema::table('project_users', function (Blueprint $table) {
            $table->dropColumn('can_add_tasks');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'share_access']);
        });
    }
};
