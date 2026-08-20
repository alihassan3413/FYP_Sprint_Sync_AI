<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Actions\EvaluateProjectHealth;
use App\Modules\Analytics\Data\HealthVerdict;
use App\Modules\Analytics\Data\ProjectHealthData;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectHealthTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private BoardColumn $todo;

    private BoardColumn $done;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Website Revamp']);

        $this->todo = $this->project->boardColumns()->where('is_done', false)->orderBy('position')->firstOrFail();
        $this->done = $this->project->boardColumns()->where('is_done', true)->firstOrFail();
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->workspace->users()->attach($user->id, ['role' => 'member']);
        $this->project->members()->attach($user->id, ['role' => ProjectRole::MEMBER->value]);

        return $user;
    }

    private function task(?User $assignee, bool $completed = false, ?string $due = null): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $completed ? $this->done->id : $this->todo->id,
            'assigned_to' => $assignee?->id,
            'completed_at' => $completed ? now() : null,
            'due_date' => $due,
        ]);
    }

    private function evaluate(): ProjectHealthData
    {
        return app(EvaluateProjectHealth::class)->handle($this->project->fresh());
    }

    /**
     * @return array<int, string>
     */
    private function codes(ProjectHealthData $health): array
    {
        return array_map(fn ($signal) => $signal->code, $health->signals);
    }

    public function test_a_project_with_no_work_is_not_judged(): void
    {
        $health = $this->evaluate();

        $this->assertSame(HealthVerdict::NoData->value, $health->verdict);
    }

    public function test_evenly_spread_work_is_healthy(): void
    {
        $sara = $this->member('Sara');
        $omar = $this->member('Omar');

        foreach ([$sara, $omar, $sara, $omar] as $person) {
            $this->task($person);
        }

        $health = $this->evaluate();

        $this->assertSame(HealthVerdict::Healthy->value, $health->verdict);
        $this->assertSame(['healthy'], $this->codes($health));
    }

    public function test_one_person_carrying_everything_is_critical(): void
    {
        $sara = $this->member('Sara');
        $this->member('Omar');

        for ($i = 0; $i < 8; $i++) {
            $this->task($sara);
        }

        $health = $this->evaluate();

        $this->assertSame(HealthVerdict::Critical->value, $health->verdict);
        $this->assertContains('workload_concentrated', $this->codes($health));
        $this->assertSame(100, $health->busiest_share_percentage);

        $finding = collect($health->signals)->firstWhere('code', 'workload_concentrated');
        $this->assertStringContainsString('Sara', $finding->headline);
        $this->assertStringContainsString('8 of the 8 open tasks', $finding->detail);
    }

    public function test_dominating_without_outweighing_everyone_is_only_a_warning(): void
    {
        $sara = $this->member('Sara');
        $omar = $this->member('Omar');
        $idris = $this->member('Idris');

        foreach ([$sara, $sara, $sara, $sara, $omar, $omar, $idris] as $person) {
            $this->task($person);
        }

        $health = $this->evaluate();

        $this->assertContains('workload_heavy', $this->codes($health));
        $this->assertNotContains('workload_concentrated', $this->codes($health));
    }

    public function test_an_even_two_way_split_is_never_flagged(): void
    {
        $sara = $this->member('Sara');
        $omar = $this->member('Omar');

        foreach ([$sara, $sara, $sara, $omar, $omar, $omar] as $person) {
            $this->task($person);
        }

        $health = $this->evaluate();

        $this->assertSame(50, $health->busiest_share_percentage);
        $this->assertNotContains('workload_heavy', $this->codes($health), 'With two people someone always holds half.');
        $this->assertNotContains('workload_concentrated', $this->codes($health));
    }

    public function test_concentration_is_not_flagged_on_a_solo_project(): void
    {
        $sara = $this->member('Sara');

        for ($i = 0; $i < 8; $i++) {
            $this->task($sara);
        }

        $this->assertNotContains('workload_concentrated', $this->codes($this->evaluate()));
    }

    public function test_concentration_needs_enough_work_to_mean_anything(): void
    {
        $sara = $this->member('Sara');
        $this->member('Omar');

        $this->task($sara);
        $this->task($sara);

        $this->assertNotContains('workload_concentrated', $this->codes($this->evaluate()));
    }

    public function test_someone_with_nothing_open_is_pointed_out(): void
    {
        $sara = $this->member('Sara');
        $omar = $this->member('Omar');

        for ($i = 0; $i < 4; $i++) {
            $this->task($sara);
        }

        $this->task($omar, completed: true);

        $codes = $this->codes($this->evaluate());

        $this->assertContains('idle_members', $codes);
    }

    public function test_overdue_pressure_is_flagged(): void
    {
        $sara = $this->member('Sara');
        $omar = $this->member('Omar');

        $this->task($sara, due: now()->subWeek()->toDateString());
        $this->task($sara, due: now()->subWeek()->toDateString());
        $this->task($omar);
        $this->task($omar);

        $this->assertContains('overdue_pressure', $this->codes($this->evaluate()));
    }

    public function test_unowned_work_is_flagged(): void
    {
        $sara = $this->member('Sara');
        $this->member('Omar');

        $this->task($sara);
        $this->task(null);
        $this->task(null);
        $this->task(null);

        $health = $this->evaluate();

        $this->assertContains('unassigned_backlog', $this->codes($health));
        $this->assertSame(3, $health->unassigned_open_tasks);
    }

    public function test_unassigned_work_never_counts_as_a_person_carrying_load(): void
    {
        $sara = $this->member('Sara');
        $this->member('Omar');

        $this->task($sara);
        for ($i = 0; $i < 6; $i++) {
            $this->task(null);
        }

        $health = $this->evaluate();

        $this->assertNotContains('workload_concentrated', $this->codes($health));
        $this->assertSame(1, $health->people_with_open_work);
    }

    public function test_finished_work_does_not_make_someone_look_busy(): void
    {
        $sara = $this->member('Sara');
        $omar = $this->member('Omar');

        /* Sara has cleared a lot; Omar is the one actually loaded right now. */
        for ($i = 0; $i < 9; $i++) {
            $this->task($sara, completed: true);
        }

        foreach ([$sara, $omar, $omar, $omar] as $person) {
            $this->task($person);
        }

        $health = $this->evaluate();
        $busiest = $health->workload[0];

        $this->assertSame('Omar', $busiest->name, 'Load is about open work, not a career total.');
        $this->assertSame(9, $health->completed_tasks);

        $saraRow = collect($health->workload)->firstWhere('name', 'Sara');
        $this->assertSame(1, $saraRow->open_tasks);
        $this->assertSame(9, $saraRow->completed_tasks);
        $this->assertSame(25, $saraRow->share_percentage);
    }

    public function test_the_workload_list_is_ordered_by_who_holds_most(): void
    {
        $sara = $this->member('Sara');
        $omar = $this->member('Omar');

        $this->task($omar);
        for ($i = 0; $i < 3; $i++) {
            $this->task($sara);
        }

        $workload = $this->evaluate()->workload;

        $this->assertSame('Sara', $workload[0]->name);
        $this->assertSame(3, $workload[0]->open_tasks);
        $this->assertSame(75, $workload[0]->share_percentage);
    }
}
