<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published SprintSync Desktop builds. The file lives on the private disk at
 * a path SprintSync generated; downloads only ever look a release up by
 * channel, platform and architecture, never by a path or URL from a request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desktop_releases', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('channel', 10);
            $table->string('platform', 10);
            $table->string('arch', 10);
            $table->string('version', 40);
            $table->string('path', 255);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('min_os', 40)->nullable();
            $table->boolean('signed')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamp('released_at');
            $table->timestamps();

            $table->unique(['channel', 'platform', 'arch', 'version']);
            $table->index(['channel', 'platform', 'arch', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_releases');
    }
};
