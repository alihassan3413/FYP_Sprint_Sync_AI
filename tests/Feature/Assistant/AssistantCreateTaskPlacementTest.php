<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Contracts\DefersConfirmation;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantCreateTaskPlacementTest extends TestCase
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

    }

    private function column(string $name, int $position, bool $isDefault = false, bool $isDone = false, ?Project $project = null): BoardColumn
    {
        return BoardColumn::factory()->create([
            'project_id' => ($project ?? $this->project)->id,
            'name' => $name,
            'position' => $position,
            'is_default' => $isDefault,
            'is_done' => $isDone,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(array $args, ?User $user = null): array
    {
        return app(CreateTaskTool::class)->execute($args, new ToolContext($user ?? $this->owner, $this->workspace));
    }

    private function tool(): CreateTaskTool
    {
        return app(CreateTaskTool::class);
    }

    public function test_a_question_is_not_presented_as_a_failure(): void
    {
        $result = $this->create(['title' => 'Fix the login redirect']);

        $this->assertTrue($result['awaiting_input'], 'The UI keys off this to avoid showing a warning.');
    }

    public function test_no_confirmation_card_is_shown_while_a_column_is_outstanding(): void
    {
        $tool = $this->tool();
        $context = new ToolContext($this->owner, $this->workspace);

        $this->assertInstanceOf(DefersConfirmation::class, $tool);
        $this->assertTrue(
            $tool->needsMoreInformation(['title' => 'Fix the login redirect'], $context),
            'Asking for a column must come before the confirmation card, not after it.',
        );
    }

    public function test_no_confirmation_card_is_shown_while_the_sprint_is_outstanding(): void
    {
        Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 4']);

        $this->assertTrue($this->tool()->needsMoreInformation(
            ['title' => 'Fix it', 'board_column' => 'To Do'],
            new ToolContext($this->owner, $this->workspace),
        ));
    }

    public function test_the_confirmation_card_returns_once_every_question_is_answered(): void
    {
        Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 4']);

        $this->assertFalse($this->tool()->needsMoreInformation(
            ['title' => 'Fix it', 'board_column' => 'To Do', 'sprint' => 'none'],
            new ToolContext($this->owner, $this->workspace),
        ), 'With nothing left to ask, the user must get a confirmation card before anything is written.');

        $this->assertTrue($this->tool()->requiresConfirmation());
    }

    public function test_a_client_is_never_deferred_because_they_are_never_asked(): void
    {
        Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 4']);

        $client = User::factory()->create();
        $this->workspace->users()->attach($client->id, ['role' => UserRole::CLIENT->value]);
        $this->project->members()->attach($client->id, ['role' => 'member']);

        $this->assertFalse($this->tool()->needsMoreInformation(
            ['title' => 'Please fix the checkout', 'project_id' => $this->project->id],
            new ToolContext($client->refresh(), $this->workspace),
        ));
    }

    public function test_it_asks_which_project_when_several_could_be_meant(): void
    {
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mobile App']);

        $result = $this->create(['title' => 'Fix the login redirect']);

        $this->assertFalse($result['success']);
        $this->assertSame('project_ambiguous', $result['error_code']);
        $this->assertCount(2, $result['projects']);
        $this->assertContains('Website Revamp', array_column($result['projects'], 'name'));
    }

    public function test_it_asks_which_column_once_the_project_is_known(): void
    {
        $result = $this->create(['title' => 'Fix the login redirect']);

        $this->assertFalse($result['success']);
        $this->assertSame('column_required', $result['error_code']);
        $this->assertSame(['To Do', 'In Progress', 'Done'], array_column($result['columns'], 'name'));
        $this->assertTrue($result['columns'][0]['is_starting_column'], 'To Do is where new work lands.');
        $this->assertFalse($result['columns'][1]['is_starting_column']);
        $this->assertTrue($result['columns'][2]['is_done_column']);
        $this->assertSame(0, Task::query()->count(), 'Nothing may be written while a question is outstanding.');
    }

    public function test_a_named_column_is_matched_loosely_and_used(): void
    {
        $result = $this->create(['title' => 'Fix the login redirect', 'board_column' => 'in progres']);

        $this->assertTrue($result['success']);
        $this->assertSame('In Progress', $result['task']['board_column']);
    }

    public function test_a_column_id_places_the_task_there(): void
    {
        $done = BoardColumn::query()->where('name', 'Done')->firstOrFail();

        $result = $this->create(['title' => 'Ship it', 'board_column_id' => $done->id]);

        $this->assertTrue($result['success']);
        $this->assertSame('Done', $result['task']['board_column']);

        $task = Task::query()->firstOrFail();
        $this->assertSame($done->id, $task->board_column_id);
        $this->assertNotNull($task->completed_at, 'Landing in a done column completes the task.');
    }

    public function test_an_unknown_column_name_returns_the_real_ones(): void
    {
        $result = $this->create(['title' => 'Fix it', 'board_column' => 'Quality Assurance']);

        $this->assertFalse($result['success']);
        $this->assertSame('column_not_found', $result['error_code']);
        $this->assertSame(['To Do', 'In Progress', 'Done'], array_column($result['columns'], 'name'));
    }

    public function test_a_single_column_project_is_not_asked_about(): void
    {
        $solo = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Solo']);
        $solo->boardColumns()->delete();
        $this->column('Only', position: 1, isDefault: true, project: $solo);

        $result = $this->create(['title' => 'Just do it', 'project_id' => $solo->id]);

        $this->assertTrue($result['success']);
        $this->assertSame('Only', $result['task']['board_column']);
    }

    public function test_it_asks_about_a_running_sprint_after_the_column_is_settled(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 4']);

        $result = $this->create(['title' => 'Fix the login redirect', 'board_column' => 'To Do']);

        $this->assertFalse($result['success']);
        $this->assertSame('sprint_choice_required', $result['error_code']);
        $this->assertSame($sprint->id, $result['sprint']['id']);
        $this->assertSame('Sprint 4', $result['sprint']['name']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_choosing_the_running_sprint_puts_the_task_in_it(): void
    {
        $sprint = Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 4']);

        $result = $this->create(['title' => 'Fix it', 'board_column' => 'To Do', 'sprint' => 'current']);

        $this->assertTrue($result['success']);
        $this->assertSame($sprint->id, $result['task']['sprint_id']);
    }

    public function test_declining_the_sprint_leaves_the_task_in_the_backlog(): void
    {
        Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 4']);

        $result = $this->create(['title' => 'Fix it', 'board_column' => 'To Do', 'sprint' => 'none']);

        $this->assertTrue($result['success']);
        $this->assertNull($result['task']['sprint_id']);
    }

    public function test_no_sprint_question_when_none_is_running(): void
    {
        $result = $this->create(['title' => 'Fix it', 'board_column' => 'To Do']);

        $this->assertTrue($result['success']);
        $this->assertNull($result['task']['sprint_id']);
    }

    public function test_a_client_is_never_asked_and_lands_in_the_default_column(): void
    {
        Sprint::factory()->forProject($this->project)->running()->create(['name' => 'Sprint 4']);

        $client = User::factory()->create();
        $this->workspace->users()->attach($client->id, ['role' => UserRole::CLIENT->value]);
        $this->project->members()->attach($client->id, ['role' => 'member']);

        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Requesting client',
            'slug' => 'requesting-client',
            'permissions' => ['client.board.view' => true, 'client.tasks.request' => true],
        ]);
        $this->workspace->users()->updateExistingPivot($client->id, ['workspace_role_id' => $role->id]);

        $result = $this->create(['title' => 'Please fix the checkout', 'project_id' => $this->project->id], $client->refresh());

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('To Do', $result['task']['board_column']);
        $this->assertNull($result['task']['sprint_id']);
    }
}
