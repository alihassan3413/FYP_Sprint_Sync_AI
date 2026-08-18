<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('email');
            $table->string('name')->nullable();

            $table->timestamps();

            $table->unique(['meeting_id', 'email']);
            $table->index(['meeting_id', 'user_id']);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->string('join_token', 64)->nullable()->unique()->after('meeting_link');
        });

        DB::table('meetings')->whereNull('join_token')->orderBy('id')->select('id')->chunkById(200, function ($meetings) {
            foreach ($meetings as $meeting) {
                DB::table('meetings')->where('id', $meeting->id)->update(['join_token' => Str::random(64)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('join_token');
        });

        Schema::dropIfExists('meeting_participants');
    }
};
