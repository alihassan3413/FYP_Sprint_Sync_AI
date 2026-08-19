<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a task first landed in a done column. This is what makes a burndown,
     * a cycle time and a per-sprint completion count computable after the fact.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('due_date');

            $table->index(['sprint_id', 'completed_at']);
        });

        DB::table('tasks')
            ->whereIn('board_column_id', DB::table('board_columns')->where('is_done', true)->select('id'))
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['sprint_id', 'completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};
