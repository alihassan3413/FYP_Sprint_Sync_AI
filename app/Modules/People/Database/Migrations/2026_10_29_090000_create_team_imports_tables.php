<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('path', 255)->nullable();
            $table->char('file_sha256', 64);
            $table->string('status', 20);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'file_sha256']);
        });

        Schema::create('team_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('role', 20)->nullable();
            $table->foreignId('workspace_role_id')->nullable()->constrained('workspace_roles')->nullOnDelete();
            $table->string('department', 80)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->foreignId('schedule_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->json('raw');
            $table->string('status', 20);
            $table->json('messages');
            $table->string('outcome', 20)->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['team_import_id', 'row_number']);
            $table->index(['team_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_import_rows');
        Schema::dropIfExists('team_imports');
    }
};
