<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
