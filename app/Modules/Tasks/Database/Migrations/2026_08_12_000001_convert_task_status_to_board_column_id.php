<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('board_column_id')->nullable()->after('status')->constrained('board_columns')->cascadeOnDelete();
        });

        $projectIds = DB::table('projects')->pluck('id');

        foreach ($projectIds as $projectId) {
            $existingDefaults = DB::table('board_columns')
                ->where('project_id', $projectId)
                ->where('is_default', true)
                ->exists();

            if ($existingDefaults) {
                continue;
            }

            $now = now();

            DB::table('board_columns')->insert([
                ['project_id' => $projectId, 'name' => 'To Do', 'position' => 0, 'is_default' => true, 'is_done' => false, 'created_at' => $now, 'updated_at' => $now],
                ['project_id' => $projectId, 'name' => 'In Progress', 'position' => 1, 'is_default' => true, 'is_done' => false, 'created_at' => $now, 'updated_at' => $now],
                ['project_id' => $projectId, 'name' => 'Done', 'position' => 2, 'is_default' => true, 'is_done' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        $columnsByProject = DB::table('board_columns')
            ->where('is_default', true)
            ->orderBy('position')
            ->get()
            ->groupBy('project_id')
            ->map(fn ($columns) => $columns->pluck('id', 'position'));

        foreach (DB::table('tasks')->select('id', 'project_id', 'status')->get() as $task) {
            $defaults = $columnsByProject->get($task->project_id);

            if ($defaults === null) {
                continue;
            }

            $position = match ($task->status) {
                'in_progress' => 1,
                'done' => 2,
                default => 0,
            };

            DB::table('tasks')->where('id', $task->id)->update([
                'board_column_id' => $defaults->get($position, $defaults->first()),
            ]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status']);
            $table->dropColumn('status');
            $table->index(['project_id', 'board_column_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('board_column_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status')->default('todo')->after('description');
        });

        foreach (DB::table('tasks')->select('id', 'board_column_id')->get() as $task) {
            $column = DB::table('board_columns')->find($task->board_column_id);

            $status = match (true) {
                $column === null => 'todo',
                $column->is_done => 'done',
                $column->position === 1 => 'in_progress',
                default => 'todo',
            };

            DB::table('tasks')->where('id', $task->id)->update(['status' => $status]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['board_column_id']);
            $table->dropIndex(['project_id', 'board_column_id']);
            $table->dropColumn('board_column_id');
            $table->index(['project_id', 'status']);
        });
    }
};
