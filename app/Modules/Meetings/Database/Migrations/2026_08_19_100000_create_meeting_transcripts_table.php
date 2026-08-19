<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_transcripts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meeting_id')->unique()->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('status', 32)->default('awaiting_audio');
            $table->string('source', 16)->nullable();

            $table->string('audio_path')->nullable();
            $table->unsignedBigInteger('audio_bytes')->nullable();

            $table->longText('text')->nullable();
            $table->string('language', 16)->nullable();

            $table->unsignedTinyInteger('confidence')->nullable();
            $table->boolean('is_low_confidence')->default(false);

            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transcribed_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_transcripts');
    }
};
