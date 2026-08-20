<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating a task should ask for a project only when it genuinely cannot tell.
 */
final class AssistantTaskProjectInferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function context(?User $user = null): ToolContext
    {
        return new ToolContext(($user ?? $this->owner)->refresh(), $this->workspace->fresh());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(array $args, ?User $user = null): array
    {
        /*
         * These tests are about assignees, sprints, audit trails and duplicates,
         * not about where on the board a task lands. create_task asks which
         * column to use when a project has more than one, so a default is
         * supplied here; the question itself is covered by
         * AssistantCreateTaskPlacementTest.
         */
        $args += ['board_column' => 'default'];

        return app(CreateTaskTool::class)->execute($args, $this->context($user));
    }

    public function test_with_one_project_the_task_is_created_without_asking(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);

        $result = $this->create(['title' => 'UI/UX modification']);

        $this->assertTrue($result['success']);
        $this->assertSame($project->id, $result['task']['project_id']);
        $this->assertSame('CIG Florida', $result['task']['project_name']);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_with_several_projects_and_no_hint_the_user_is_asked(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Acme Internal']);

        $result = $this->create(['title' => 'UI/UX modification']);

        $this->assertFalse($result['success']);
        $this->assertSame('project_ambiguous', $result['error_code']);
        $this->assertCount(2, $result['projects']);
        $this->assertStringContainsString('Ask the user which project', $result['error']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_project_named_in_passing_is_matched_loosely(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Acme Internal']);
        $cig = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);

        $result = $this->create(['title' => 'UI/UX modification', 'project_name' => 'cig florida']);

        $this->assertTrue($result['success']);
        $this->assertSame($cig->id, $result['task']['project_id']);
    }

    public function test_a_project_name_that_fits_two_projects_asks_rather_than_guessing(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Texas']);

        $result = $this->create(['title' => 'UI/UX modification', 'project_name' => 'CIG']);

        $this->assertFalse($result['success']);
        $this->assertSame('project_ambiguous', $result['error_code']);
        $this->assertCount(2, $result['projects']);
        $this->assertArrayHasKey('match_confidence', $result['projects'][0]);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_an_unknown_project_name_comes_back_with_the_real_ones(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);

        $result = $this->create(['title' => 'Anything', 'project_name' => 'Zeta Motors']);

        $this->assertFalse($result['success']);
        $this->assertSame('project_not_found', $result['error_code']);
        $this->assertSame('CIG Florida', $result['projects'][0]['name']);
    }

    public function test_a_member_on_exactly_one_project_also_skips_the_question(): void
    {
        $visible = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'CIG Florida']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Hidden']);

        $manager = User::factory()->create();
        $this->workspace->users()->attach($manager->id, ['role' => UserRole::MEMBER->value]);
        $visible->members()->attach($manager->id, ['role' => ProjectRole::MANAGER->value]);

        $result = $this->create(['title' => 'Scoped task'], $manager);

        $this->assertTrue($result['success']);
        $this->assertSame($visible->id, $result['task']['project_id']);
    }

    public function test_a_person_can_be_named_without_an_email_address(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $rana = User::factory()->create(['name' => 'Rana Dev']);
        $this->workspace->users()->attach($rana->id, ['role' => UserRole::MEMBER->value]);
        $project->members()->attach($rana->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->create(['title' => 'UI/UX modification', 'assignee' => 'Rana']);

        $this->assertTrue($result['success']);
        $this->assertSame('Rana Dev', $result['task']['assignee_name']);
    }

    public function test_two_similar_names_stop_the_assignment_rather_than_guessing(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        foreach (['Rana Dev', 'Rana Ahmed'] as $name) {
            $person = User::factory()->create(['name' => $name]);
            $this->workspace->users()->attach($person->id, ['role' => UserRole::MEMBER->value]);
            $project->members()->attach($person->id, ['role' => ProjectRole::MEMBER->value]);
        }

        $result = $this->create(['title' => 'UI/UX modification', 'assignee' => 'Rana']);

        $this->assertFalse($result['success']);
        $this->assertSame('assignee_ambiguous', $result['error_code']);
        $this->assertCount(2, $result['people']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_near_duplicate_task_is_pointed_out_after_creation(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $existing = Task::factory()->create([
            'title' => 'UI/UX modification',
            'project_id' => $project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $project->boardColumns()->value('id'),
        ]);

        $result = $this->create(['title' => 'UI/UX modification']);

        $this->assertTrue($result['success']);
        $this->assertSame($existing->id, $result['similar_existing_task']['task_id']);
        $this->assertStringContainsString('similar task already exists', $result['next_step']);
    }

    public function test_unrelated_tasks_do_not_trigger_a_duplicate_warning(): void
    {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        Task::factory()->create([
            'title' => 'Write onboarding copy',
            'project_id' => $project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $project->boardColumns()->value('id'),
        ]);

        $result = $this->create(['title' => 'UI/UX modification']);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('similar_existing_task', $result);
    }

    public function test_someone_with_no_projects_is_told_so(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create();

        $outsider = User::factory()->create();
        $this->workspace->users()->attach($outsider->id, ['role' => UserRole::MEMBER->value]);

        $result = $this->create(['title' => 'Nowhere to go'], $outsider);

        $this->assertFalse($result['success']);
        $this->assertSame('no_projects', $result['error_code']);
        $this->assertStringContainsString('not on any project', $result['error']);
    }
}
