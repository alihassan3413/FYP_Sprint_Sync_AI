<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-wide administrator flag.
     *
     * Deliberately separate from the workspace role system: those roles are
     * scoped to a single workspace, while this grants read access to the
     * cross-tenant admin panel. It is not wired into Gate::before, so it does
     * not silently satisfy every workspace policy in the app.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->index()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_super_admin']);
            $table->dropColumn('is_super_admin');
        });
    }
};
