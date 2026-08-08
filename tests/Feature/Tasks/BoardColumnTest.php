<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BoardColumnTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
    }

    private function columnRoute(string $name, ?BoardColumn $column = null, array $extra = []): string
    {
        $params = array_merge(['workspace' => $this->workspace, 'project' => $this->project], $extra);

        if ($column !== null) {
            $params['boardColumn'] = $column;
        }

        return route($name, $params);
    }

    public function test_an_owner_can_add_a_custom_column(): void
    {
        $this->actingAs($this->owner)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'QA'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('board_columns', [
            'project_id' => $this->project->id,
            'name' => 'QA',
            'is_default' => false,
            'position' => 3,
        ]);
    }

    public function test_a_project_manager_can_add_a_custom_column(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'QA'])
            ->assertRedirect();

        $this->assertDatabaseHas('board_columns', ['project_id' => $this->project->id, 'name' => 'QA']);
    }

    public function test_a_project_manager_of_another_project_cannot_add_a_column_here(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $otherProject->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'QA'])
            ->assertForbidden();
    }

    public function test_a_project_member_cannot_add_a_column(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'QA'])
            ->assertForbidden();

        $this->assertDatabaseMissing('board_columns', ['project_id' => $this->project->id, 'name' => 'QA']);
    }

    public function test_an_unassigned_workspace_member_cannot_add_a_column(): void
    {
        $this->actingAs($this->member)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'QA'])
            ->assertForbidden();
    }

    public function test_an_outsider_cannot_add_a_column(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'QA'])
            ->assertNotFound();
    }

    public function test_the_name_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_new_columns_are_appended_after_existing_ones(): void
    {
        $this->actingAs($this->owner)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'QA'])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post($this->columnRoute('workspace.projects.board-columns.store'), ['name' => 'Deployed'])
            ->assertRedirect();

        $this->assertDatabaseHas('board_columns', ['name' => 'QA', 'position' => 3]);
        $this->assertDatabaseHas('board_columns', ['name' => 'Deployed', 'position' => 4]);
    }

    public function test_an_owner_can_delete_an_empty_custom_column(): void
    {
        $column = $this->project->boardColumns()->create(['name' => 'QA', 'position' => 3]);

        $this->actingAs($this->owner)
            ->delete($this->columnRoute('workspace.projects.board-columns.destroy', $column))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('board_columns', ['id' => $column->id]);
    }

    public function test_a_default_column_cannot_be_deleted(): void
    {
        $column = $this->project->boardColumns()->where('position', 0)->firstOrFail();

        $this->actingAs($this->owner)
            ->delete($this->columnRoute('workspace.projects.board-columns.destroy', $column))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('board_columns', ['id' => $column->id]);
    }

    public function test_a_non_empty_column_cannot_be_deleted(): void
    {
        $column = $this->project->boardColumns()->create(['name' => 'QA', 'position' => 3]);
        $task = Task::factory()->forProject($this->project)->forColumn($column)->create();

        $this->actingAs($this->owner)
            ->delete($this->columnRoute('workspace.projects.board-columns.destroy', $column))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('board_columns', ['id' => $column->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'board_column_id' => $column->id]);
    }

    public function test_a_project_member_cannot_delete_a_column(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $column = $this->project->boardColumns()->create(['name' => 'QA', 'position' => 3]);

        $this->actingAs($this->member)
            ->delete($this->columnRoute('workspace.projects.board-columns.destroy', $column))
            ->assertForbidden();

        $this->assertDatabaseHas('board_columns', ['id' => $column->id]);
    }

    public function test_a_column_from_another_project_cannot_be_deleted_through_this_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $foreignColumn = $otherProject->boardColumns()->create(['name' => 'QA', 'position' => 3]);

        $this->actingAs($this->owner)
            ->delete($this->columnRoute('workspace.projects.board-columns.destroy', $foreignColumn))
            ->assertNotFound();

        $this->assertDatabaseHas('board_columns', ['id' => $foreignColumn->id]);
    }

    public function test_a_column_from_another_workspace_cannot_be_reached_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();
        $foreignColumn = $otherProject->boardColumns()->create(['name' => 'QA', 'position' => 3]);

        $this->actingAs($this->owner)
            ->delete($this->columnRoute('workspace.projects.board-columns.destroy', $foreignColumn))
            ->assertNotFound();

        $this->assertDatabaseHas('board_columns', ['id' => $foreignColumn->id]);
    }

    public function test_an_owner_can_reorder_columns(): void
    {
        $columns = $this->project->boardColumns()->orderBy('position')->get();
        $newOrder = [$columns[2]->id, $columns[0]->id, $columns[1]->id];

        $this->actingAs($this->owner)
            ->patch($this->columnRoute('workspace.projects.board-columns.reorder'), ['column_ids' => $newOrder])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $columns[2]->fresh()->position);
        $this->assertSame(1, $columns[0]->fresh()->position);
        $this->assertSame(2, $columns[1]->fresh()->position);
    }

    public function test_a_project_manager_can_reorder_columns(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);
        $columns = $this->project->boardColumns()->orderBy('position')->get();
        $newOrder = [$columns[1]->id, $columns[0]->id, $columns[2]->id];

        $this->actingAs($this->member)
            ->patch($this->columnRoute('workspace.projects.board-columns.reorder'), ['column_ids' => $newOrder])
            ->assertRedirect();

        $this->assertSame(0, $columns[1]->fresh()->position);
        $this->assertSame(1, $columns[0]->fresh()->position);
    }

    public function test_a_project_manager_of_another_project_cannot_reorder_this_projects_columns(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $otherProject->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);
        $columns = $this->project->boardColumns()->orderBy('position')->get();

        $this->actingAs($this->member)
            ->patch($this->columnRoute('workspace.projects.board-columns.reorder'), [
                'column_ids' => [$columns[1]->id, $columns[0]->id, $columns[2]->id],
            ])
            ->assertForbidden();
    }

    public function test_a_project_member_cannot_reorder_columns(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $columns = $this->project->boardColumns()->orderBy('position')->get();

        $this->actingAs($this->member)
            ->patch($this->columnRoute('workspace.projects.board-columns.reorder'), [
                'column_ids' => [$columns[1]->id, $columns[0]->id, $columns[2]->id],
            ])
            ->assertForbidden();
    }

    public function test_reordering_rejects_a_column_id_from_another_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $foreignColumn = $otherProject->boardColumns()->where('position', 0)->firstOrFail();
        $columns = $this->project->boardColumns()->orderBy('position')->get();

        $this->actingAs($this->owner)
            ->patch($this->columnRoute('workspace.projects.board-columns.reorder'), [
                'column_ids' => [$foreignColumn->id, $columns[1]->id, $columns[2]->id],
            ])
            ->assertSessionHasErrors('column_ids.0');
    }

    public function test_reordering_rejects_a_partial_list(): void
    {
        $columns = $this->project->boardColumns()->orderBy('position')->get();

        $this->actingAs($this->owner)
            ->patch($this->columnRoute('workspace.projects.board-columns.reorder'), [
                'column_ids' => [$columns[0]->id, $columns[1]->id],
            ])
            ->assertSessionHasErrors('column_ids');
    }

    public function test_reordering_rejects_duplicate_ids(): void
    {
        $columns = $this->project->boardColumns()->orderBy('position')->get();

        $this->actingAs($this->owner)
            ->patch($this->columnRoute('workspace.projects.board-columns.reorder'), [
                'column_ids' => [$columns[0]->id, $columns[0]->id, $columns[1]->id],
            ])
            ->assertSessionHasErrors();
    }
}
