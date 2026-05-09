<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration {
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('assistant_conversations')
                ->cascadeOnDelete();


            $table->string('role', 20);

            $table->longText('content')->nullable();

            $table->json('tool_calls')->nullable();


            $table->string('tool_call_id', 64)->nullable();

            $table->string('tool_status', 20)->nullable();

            $table->string('provider', 20)->nullable();
            $table->string('model', 60)->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);


            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);

            $table->index('tool_call_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
