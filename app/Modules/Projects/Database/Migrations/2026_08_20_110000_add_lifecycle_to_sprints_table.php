<?php

declare(strict_types=1);

use App\Modules\Projects\Data\SprintStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sprints', function (Blueprint $table) {
            $table->string('status')->default(SprintStatus::Planned->value)->after('goal');
            $table->timestamp('started_at')->nullable()->after('ends_on');
            $table->timestamp('completed_at')->nullable()->after('started_at');

            /* Frozen at start and at completion so history survives later task edits. */
            $table->unsignedInteger('committed_task_count')->nullable()->after('completed_at');
            $table->unsignedInteger('completed_task_count')->nullable()->after('committed_task_count');
            $table->unsignedInteger('carried_over_task_count')->nullable()->after('completed_task_count');

            $table->index(['project_id', 'status']);
        });

        $today = now()->toDateString();

        DB::table('sprints')->whereDate('ends_on', '<', $today)->update([
            'status' => SprintStatus::Completed->value,
            'started_at' => DB::raw('starts_on'),
            'completed_at' => DB::raw('ends_on'),
        ]);

        DB::table('sprints')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->update([
                'status' => SprintStatus::Active->value,
                'started_at' => DB::raw('starts_on'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sprints', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status']);
            $table->dropColumn([
                'status',
                'started_at',
                'completed_at',
                'committed_task_count',
                'completed_task_count',
                'carried_over_task_count',
            ]);
        });
    }
};
