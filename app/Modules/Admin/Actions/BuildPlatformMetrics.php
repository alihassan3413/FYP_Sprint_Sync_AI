<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Admin\Data\PlatformMetricsData;
use App\Modules\Admin\Data\SignupPointData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Whole-platform counts for the admin panel. Deliberately unscoped: this is
 * the one place that reports across every workspace.
 */
final class BuildPlatformMetrics
{
    private const SIGNUP_WINDOW_DAYS = 30;

    public function handle(): PlatformMetricsData
    {
        return new PlatformMetricsData(
            users_total: User::query()->count(),
            users_verified: User::query()->whereNotNull('email_verified_at')->count(),
            users_new_30d: User::query()->where('created_at', '>=', $this->windowStart())->count(),
            workspaces_total: Workspace::query()->count(),
            workspaces_active: Workspace::query()->where('is_active', true)->count(),
            projects_total: Project::query()->count(),
            tasks_total: Task::query()->count(),
            tasks_completed: Task::query()->whereNotNull('completed_at')->count(),
            sprints_total: Sprint::query()->count(),
            meetings_total: Meeting::query()->count(),
            signups: $this->signupSeries(),
        );
    }

    private function windowStart(): Carbon
    {
        return now()->subDays(self::SIGNUP_WINDOW_DAYS)->startOfDay();
    }

    /**
     * One point per day across the window, including days with no signups so
     * the chart keeps an even horizontal scale.
     *
     * @return array<int, SignupPointData>
     */
    private function signupSeries(): array
    {
        $counts = User::query()
            ->where('created_at', '>=', $this->windowStart())
            ->get(['created_at'])
            ->countBy(fn (User $user) => $user->created_at->toDateString());

        $series = [];

        for ($day = self::SIGNUP_WINDOW_DAYS; $day >= 0; $day--) {
            $date = now()->subDays($day)->toDateString();

            $series[] = new SignupPointData(
                date: $date,
                count: (int) ($counts[$date] ?? 0),
            );
        }

        return $series;
    }
}
