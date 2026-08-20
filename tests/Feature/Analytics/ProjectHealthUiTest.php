<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectHealthUiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Website Revamp']);
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    /**
     * A partial reload, which is how a deferred prop is actually fetched.
     * Without the asset version Inertia answers 409 and asks for a full reload.
     *
     * @return array<string, string>
     */
    private function partial(string $component, string $only): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $only,
        ];
    }

    private function overload(): void
    {
        $sara = User::factory()->create(['name' => 'Sara']);
        $omar = User::factory()->create(['name' => 'Omar']);

        foreach ([$sara, $omar] as $person) {
            $this->workspace->users()->attach($person->id, ['role' => UserRole::MEMBER->value]);
            $this->project->members()->attach($person->id, ['role' => ProjectRole::MEMBER->value]);
        }

        $column = $this->project->boardColumns()->where('is_done', false)->orderBy('position')->firstOrFail();

        for ($i = 0; $i < 8; $i++) {
            Task::factory()->create([
                'project_id' => $this->project->id,
                'workspace_id' => $this->workspace->id,
                'board_column_id' => $column->id,
                'assigned_to' => $sara->id,
                'completed_at' => null,
                'due_date' => null,
            ]);
        }
    }

    public function test_the_analytics_page_delivers_project_health(): void
    {
        $this->overload();

        $this->actingAs($this->owner)
            ->withHeaders($this->partial('analytics/index', 'health'))
            ->get(route('workspace.analytics.index', ['workspace' => $this->workspace->slug]))
            ->assertOk()
            ->assertJsonCount(1, 'props.health')
            ->assertJsonPath('props.health.0.verdict', 'critical')
            ->assertJsonPath('props.health.0.project_name', 'Website Revamp');
    }

    public function test_the_project_page_delivers_its_own_health(): void
    {
        $this->overload();

        $this->actingAs($this->owner)
            ->withHeaders($this->partial('projects/show', 'health'))
            ->get(route('workspace.projects.show', ['workspace' => $this->workspace->slug, 'project' => $this->project->id]))
            ->assertOk()
            ->assertJsonPath('props.health.verdict', 'critical');
    }

    public function test_the_dashboard_surfaces_the_most_serious_finding(): void
    {
        $this->overload();

        $this->actingAs($this->owner)
            ->withHeaders($this->partial('Dashboard', 'insight'))
            ->get(route('dashboard', ['workspace' => $this->workspace->slug]))
            ->assertOk()
            ->assertJsonPath('props.insight.severity', 'critical')
            ->assertJsonPath('props.insight.project_name', 'Website Revamp')
            ->assertSee('Sara');
    }

    public function test_a_quiet_workspace_gets_no_dashboard_insight(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->partial('Dashboard', 'insight'))
            ->get(route('dashboard', ['workspace' => $this->workspace->slug]))
            ->assertOk()
            ->assertJsonPath('props.insight', null);
    }

    public function test_a_client_is_never_shown_team_workload_on_the_dashboard(): void
    {
        $this->overload();

        $client = User::factory()->create();
        $this->workspace->users()->attach($client->id, ['role' => UserRole::CLIENT->value]);
        $this->project->members()->attach($client->id, ['role' => ProjectRole::MEMBER->value]);
        $client->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->actingAs($client->refresh())
            ->withHeaders($this->partial('Dashboard', 'insight'))
            ->get(route('dashboard', ['workspace' => $this->workspace->slug]))
            ->assertOk()
            ->assertJsonPath('props.insight', null);
    }
}
