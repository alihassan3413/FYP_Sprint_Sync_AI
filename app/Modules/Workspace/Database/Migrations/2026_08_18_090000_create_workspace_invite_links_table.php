<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invite_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('token', 64)->unique();
            $table->unsignedInteger('uses')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invite_links');
    }
};
