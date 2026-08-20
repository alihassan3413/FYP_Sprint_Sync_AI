<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\CommandCatalog;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\EvaluateProjectTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantEvaluateProjectToolTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->alpha = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Website Revamp']);
    }

    private function tool(): EvaluateProjectTool
    {
        return app(EvaluateProjectTool::class);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function evaluate(array $args = [], ?User $user = null): array
    {
        return $this->tool()->execute($args, new ToolContext($user ?? $this->owner, $this->workspace));
    }

    private function loadedProject(): User
    {
        $sara = User::factory()->create(['name' => 'Sara']);
        $omar = User::factory()->create(['name' => 'Omar']);

        foreach ([$sara, $omar] as $person) {
            $this->workspace->users()->attach($person->id, ['role' => UserRole::MEMBER->value]);
            $this->alpha->members()->attach($person->id, ['role' => ProjectRole::MEMBER->value]);
        }

        $column = $this->alpha->boardColumns()->where('is_done', false)->orderBy('position')->firstOrFail();

        for ($i = 0; $i < 8; $i++) {
            Task::factory()->create([
                'project_id' => $this->alpha->id,
                'workspace_id' => $this->workspace->id,
                'board_column_id' => $column->id,
                'assigned_to' => $sara->id,
                'completed_at' => null,
                'due_date' => null,
            ]);
        }

        return $sara;
    }

    public function test_the_tool_is_registered_with_palette_copy(): void
    {
        $this->assertNotNull(app(ToolRegistry::class)->get('evaluate_project'));
        $this->assertContains('evaluate_project', CommandCatalog::describedToolNames());
    }

    public function test_it_never_asks_for_confirmation(): void
    {
        $this->assertFalse($this->tool()->requiresConfirmation());
    }

    public function test_it_names_who_is_carrying_the_project(): void
    {
        $this->loadedProject();

        $result = $this->evaluate(['project_name' => 'Website Revamp']);

        $this->assertTrue($result['success']);
        $this->assertSame('project', $result['scope']);
        $this->assertSame('critical', $result['assessment']['verdict']);

        $codes = array_column($result['assessment']['findings'], 'code');
        $this->assertContains('workload_concentrated', $codes);

        $finding = collect($result['assessment']['findings'])->firstWhere('code', 'workload_concentrated');
        $this->assertStringContainsString('Sara', $finding['headline']);
    }

    public function test_the_workload_rows_show_each_persons_share(): void
    {
        $this->loadedProject();

        $result = $this->evaluate(['project_name' => 'Website Revamp']);
        $workload = $result['assessment']['workload'];

        $this->assertSame('Sara', $workload[0]['name']);
        $this->assertSame(8, $workload[0]['open_tasks']);
        $this->assertSame(100, $workload[0]['share_percentage']);
    }

    public function test_with_no_project_named_it_sweeps_every_project(): void
    {
        $this->loadedProject();
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mobile App']);

        $result = $this->evaluate();

        $this->assertSame('workspace', $result['scope']);
        $this->assertSame(2, $result['assessed']);
        $this->assertEqualsCanonicalizing(
            ['Website Revamp', 'Mobile App'],
            array_column($result['assessments'], 'project_name'),
        );
    }

    public function test_an_unknown_project_is_not_guessed_at(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mobile App']);

        $result = $this->evaluate(['project_name' => 'Something Else Entirely']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('projects', $result);
    }

    public function test_a_client_cannot_see_team_workload(): void
    {
        $client = User::factory()->create();
        $this->workspace->users()->attach($client->id, ['role' => UserRole::CLIENT->value]);
        $this->alpha->members()->attach($client->id, ['role' => ProjectRole::MEMBER->value]);

        $this->assertFalse(
            $this->tool()->authorize(new ToolContext($client->refresh(), $this->workspace)),
            'Who on the delivery team is overloaded is not a client\'s business.',
        );
    }

    public function test_an_owner_may_use_it(): void
    {
        $this->assertTrue($this->tool()->authorize(new ToolContext($this->owner, $this->workspace)));
    }

    public function test_a_project_with_nothing_in_it_is_not_judged(): void
    {
        $result = $this->evaluate(['project_name' => 'Website Revamp']);

        $this->assertSame('no_data', $result['assessment']['verdict']);
    }
}
