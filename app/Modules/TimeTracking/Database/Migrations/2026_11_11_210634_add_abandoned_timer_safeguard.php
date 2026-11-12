<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_policies', function (Blueprint $table) {
            $table->unsignedInteger('auto_stop_after_shift_seconds')->nullable()->after('integrity_enabled');
            $table->unsignedInteger('max_timer_seconds')->nullable()->after('auto_stop_after_shift_seconds');
        });

        Schema::table('time_sessions', function (Blueprint $table) {
            $table->string('stop_reason', 24)->nullable()->after('stopped_at');
        });
    }

    public function down(): void
    {
        Schema::table('time_sessions', function (Blueprint $table) {
            $table->dropColumn('stop_reason');
        });

        Schema::table('time_policies', function (Blueprint $table) {
            $table->dropColumn(['auto_stop_after_shift_seconds', 'max_timer_seconds']);
        });
    }
};
