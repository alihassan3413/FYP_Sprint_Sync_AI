<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills an account with enough workspaces, projects, sprints, tasks, comments
 * and meetings to demo the product against.
 */
final class SeedDemoData extends Command
{
    protected $signature = 'demo:seed
        {--email=aliupwork6@gmail.com : Account the data is created for}
        {--workspaces=2 : Workspaces to create}
        {--projects=5 : Projects per workspace}
        {--fresh : Delete this account\'s existing workspaces first}';

    protected $description = 'Seed demo workspaces, projects, sprints, tasks and meetings for an account';

    public function handle(): int
    {
        // Factories need Faker, which is require-dev and so absent from a
        // production `composer install --no-dev`. Laravel only defines fake()
        // when Faker is present, so without this the failure is an undefined
        // function deep inside a factory.
        if (! function_exists('fake')) {
            $this->error('Faker is missing — this command needs dev dependencies.');
            $this->line('Run: composer install --optimize-autoloader   (as the app user, without --no-dev)');

            return self::FAILURE;
        }

        $email = (string) $this->option('email');

        $user = User::firstWhere('email', $email);

        if ($user === null) {
            $user = User::factory()->create(['name' => 'Ali Hassan', 'email' => $email]);
            $this->warn("Created {$email} with password: password");
        }

        if ($this->option('fresh')) {
            $deleted = Workspace::where('owner_id', $user->id)->get()->each->delete()->count();
            $this->warn("Deleted {$deleted} existing workspace(s).");
        }

        $teammates = $this->teammates();

        // One transaction: a half-seeded workspace is worse than none.
        DB::transaction(function () use ($user, $teammates) {
            $workspaces = (int) $this->option('workspaces');

            for ($i = 1; $i <= $workspaces; $i++) {
                $this->seedWorkspace($user, $teammates, $i);
            }

            $user->forceFill(['current_workspace_id' => Workspace::where('owner_id', $user->id)->value('id')])->save();
        });

        $this->newLine();
        $this->info("Done. Sign in as {$email}.");
        $ids = Workspace::where('owner_id', $user->id)->pluck('id');

        $this->table(
            ['Workspaces', 'Projects', 'Sprints', 'Tasks', 'Comments', 'Meetings'],
            [[
                $ids->count(),
                Project::whereIn('workspace_id', $ids)->count(),
                Sprint::whereIn('workspace_id', $ids)->count(),
                Task::whereIn('workspace_id', $ids)->count(),
                TaskComment::whereIn('task_id', Task::whereIn('workspace_id', $ids)->select('id'))->count(),
                Meeting::whereIn('workspace_id', $ids)->count(),
            ]],
        );

        return self::SUCCESS;
    }

    /** @return array<int, User> */
    private function teammates(): array
    {
        $names = [
            'Sara Ahmed', 'Bilal Khan', 'Ayesha Malik', 'Usman Tariq',
            'Hina Raza', 'Daniyal Sheikh', 'Zainab Iqbal', 'Omar Farooq',
        ];

        return collect($names)
            ->map(function (string $name) {
                $email = str($name)->lower()->replace(' ', '.').'@sprintsync.test';

                return User::firstWhere('email', $email)
                    ?? User::factory()->create(['name' => $name, 'email' => $email]);
            })
            ->all();
    }

    /** @param  array<int, User>  $teammates */
    private function seedWorkspace(User $owner, array $teammates, int $index): void
    {
        $names = ['Sprint Sync HQ', 'Client Delivery', 'Internal R&D'];

        $workspace = Workspace::factory()
            ->ownedBy($owner)
            ->create(['name' => $names[$index - 1] ?? "Workspace {$index}"]);

        foreach ($teammates as $position => $teammate) {
            $workspace->users()->syncWithoutDetaching([
                $teammate->id => ['role' => ($position === 0 ? UserRole::ADMIN : UserRole::MEMBER)->value],
            ]);
        }

        $this->line("Workspace: {$workspace->name}");

        for ($i = 1; $i <= (int) $this->option('projects'); $i++) {
            $this->seedProject($workspace, $owner, $teammates);
        }
    }

    /** @param  array<int, User>  $teammates */
    private function seedProject(Workspace $workspace, User $owner, array $teammates): void
    {
        $project = Project::factory()->forWorkspace($workspace)->create();

        $members = collect($teammates)->random(4)->all();

        foreach ($members as $member) {
            $project->members()->syncWithoutDetaching([$member->id => ['role' => ProjectRole::MEMBER->value]]);
        }

        $project->members()->syncWithoutDetaching([$owner->id => ['role' => ProjectRole::MANAGER->value]]);

        $assignees = [...$members, $owner];

        $sprints = [
            Sprint::factory()->forProject($project)->past()->completed(completedTasks: 12, carriedOver: 3)->create(),
            Sprint::factory()->forProject($project)->current()->running()->create(),
            Sprint::factory()->forProject($project)->upcoming()->planned()->create(),
        ];

        $columns = $project->boardColumns()->orderBy('position')->get();
        $doneColumn = $columns->firstWhere('is_done', true);

        foreach ($sprints as $sprint) {
            $this->seedTasks($project, $sprint, $columns, $doneColumn, $assignees);
        }

        // Backlog: tasks belonging to no sprint, so the backlog view is not empty.
        $this->seedTasks($project, null, $columns, $doneColumn, $assignees, count: 8);

        Meeting::factory()
            ->count(3)
            ->forProject($project)
            ->createdBy($owner)
            ->withParticipants(...array_slice($assignees, 0, 3))
            ->create();

        AuditLog::factory()->count(10)->forWorkspace($workspace)->forProject($project)->byUser($owner)
            ->action(AuditAction::TASK_CREATED)->create();

        $this->line("  · {$project->name}");
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     * @param  array<int, User>  $assignees
     */
    private function seedTasks(
        Project $project,
        ?Sprint $sprint,
        $columns,
        ?BoardColumn $doneColumn,
        array $assignees,
        int $count = 18,
    ): void {
        for ($i = 0; $i < $count; $i++) {
            $column = $columns->random();
            $isDone = $doneColumn !== null && $column->id === $doneColumn->id;

            $task = Task::factory()
                ->forProject($project)
                ->forColumn($column)
                ->assignedTo(fake()->randomElement($assignees))
                ->create([
                    'sprint_id' => $sprint?->id,
                    'due_date' => fake()->dateTimeBetween('-2 weeks', '+3 weeks')->format('Y-m-d'),
                    // Analytics counts completed work off this, not the column,
                    // so a done task without it leaves the charts empty.
                    'completed_at' => $isDone ? fake()->dateTimeBetween('-3 weeks', 'now') : null,
                ]);

            if (fake()->boolean(40)) {
                TaskComment::factory()
                    ->count(fake()->numberBetween(1, 3))
                    ->forTask($task)
                    ->by(fake()->randomElement($assignees))
                    ->create();
            }
        }
    }
}
