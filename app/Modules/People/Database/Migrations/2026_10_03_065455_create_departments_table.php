<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace's departments (Engineering, Design, Customer Support…).
 *
 * name_key is the trimmed, lower-cased name. Its unique index is what makes
 * "Design", "design " and a retried request all resolve to one department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('name_key', 80);
            $table->timestamps();

            $table->unique(['workspace_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
