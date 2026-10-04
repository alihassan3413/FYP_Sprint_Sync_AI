<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An opaque id for referring to a project outside its own pages (time
 * tracking, the future desktop timer), so the sequential id is never sent.
 * Existing project routes keep using the numeric id for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique()->after('id');
        });

        DB::table('projects')->whereNull('public_id')->orderBy('id')->each(function (object $project) {
            DB::table('projects')->where('id', $project->id)->update(['public_id' => strtolower((string) Str::ulid())]);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
