<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->manager = User::factory()->create();
        $this->workspace->users()->attach($this->manager->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
        $this->project->members()->attach($this->manager->id, ['role' => ProjectRole::MANAGER->value]);
    }

    private function route(string $name, array $extra = []): string
    {
        return route($name, array_merge(['workspace' => $this->workspace, 'project' => $this->project], $extra));
    }

    public function test_creating_a_task_records_an_audit_entry_with_the_correct_actor_and_scope(): void
    {
        $this->actingAs($this->owner)
            ->post($this->route('workspace.projects.tasks.store'), ['title' => 'Fix checkout bug'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->owner->id,
            'action' => AuditAction::TASK_CREATED->value,
        ]);

        $entry = AuditLog::query()->where('action', AuditAction::TASK_CREATED->value)->firstOrFail();
        $this->assertStringContainsString($this->owner->name, $entry->description);
        $this->assertStringContainsString('Fix checkout bug', $entry->description);
    }

    public function test_updating_a_task_title_records_an_audit_entry_without_leaking_the_full_description_text(): void
    {
        $task = Task::factory()->forProject($this->project)->create(['title' => 'Old title', 'description' => 'Sensitive internal notes']);

        $this->actingAs($this->owner)
            ->put($this->route('workspace.projects.tasks.update', ['task' => $task]), [
                'title' => 'New title',
                'description' => 'Sensitive internal notes',
            ])
            ->assertRedirect();

        $entry = AuditLog::query()->where('action', AuditAction::TASK_UPDATED->value)->firstOrFail();
        $this->assertStringContainsString('New title', $entry->description);
        $this->assertStringNotContainsString('Sensitive internal notes', json_encode($entry->metadata));
        $this->assertContains('title', $entry->metadata['changed_fields']);
    }

    public function test_moving_a_task_between_columns_records_an_audit_entry_matching_the_expected_message_style(): void
    {
        $task = Task::factory()->forProject($this->project)->create();
        $inProgress = BoardColumn::query()->where('project_id', $this->project->id)->where('name', 'In Progress')->firstOrFail();

        $this->actingAs($this->owner)
            ->patch($this->route('workspace.projects.tasks.update-status', ['task' => $task]), [
                'board_column_id' => $inProgress->id,
            ])
            ->assertRedirect();

        $entry = AuditLog::query()->where('action', AuditAction::TASK_MOVED->value)->firstOrFail();
        $this->assertSame(
            "{$this->owner->name} moved \"{$task->title}\" from To Do to In Progress.",
            $entry->description,
        );
    }

    public function test_assigning_a_task_records_a_distinct_audit_entry_from_updating_it(): void
    {
        $task = Task::factory()->forProject($this->project)->create(['description' => null, 'due_date' => null]);

        $this->actingAs($this->owner)
            ->put($this->route('workspace.projects.tasks.update', ['task' => $task]), [
                'title' => $task->title,
                'assigned_to' => $this->manager->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::TASK_ASSIGNED->value,
            'user_id' => $this->owner->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::TASK_UPDATED->value]);
    }

    public function test_deleting_a_task_records_an_audit_entry(): void
    {
        $task = Task::factory()->forProject($this->project)->create(['title' => 'Ship the release']);

        $this->actingAs($this->owner)
            ->delete($this->route('workspace.projects.tasks.destroy', ['task' => $task]))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::TASK_DELETED->value,
            'description' => "{$this->owner->name} deleted \"Ship the release\".",
        ]);
    }

    public function test_scheduling_updating_and_cancelling_a_meeting_each_record_an_audit_entry(): void
    {
        $this->actingAs($this->owner)
            ->post($this->route('workspace.projects.meetings.store'), [
                'title' => 'Sprint Planning',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $meeting = Meeting::query()->where('title', 'Sprint Planning')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::MEETING_SCHEDULED->value, 'subject_id' => $meeting->id]);

        $this->actingAs($this->owner)
            ->put($this->route('workspace.projects.meetings.update', ['meeting' => $meeting]), [
                'title' => 'Sprint Planning (moved)',
                'scheduled_at' => '2026-09-02 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::MEETING_UPDATED->value]);

        $this->actingAs($this->owner)
            ->delete($this->route('workspace.projects.meetings.destroy', ['meeting' => $meeting]))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::MEETING_CANCELLED->value,
            'description' => "{$this->owner->name} cancelled \"Sprint Planning (moved)\".",
        ]);
    }

    public function test_project_member_added_removed_and_role_changed_each_record_an_audit_entry(): void
    {
        $newMember = User::factory()->create();
        $this->workspace->users()->attach($newMember->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->owner)
            ->post($this->route('workspace.projects.members.store'), [
                'user_id' => $newMember->id,
                'role' => ProjectRole::MEMBER->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PROJECT_MEMBER_ADDED->value,
            'subject_id' => $newMember->id,
        ]);

        $this->actingAs($this->owner)
            ->patch($this->route('workspace.projects.members.update', ['member' => $newMember]), [
                'role' => ProjectRole::MANAGER->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PROJECT_MEMBER_ROLE_CHANGED->value,
            'description' => "{$this->owner->name} assigned {$newMember->name} as Project Manager in \"{$this->project->name}\".",
        ]);

        $this->actingAs($this->owner)
            ->delete($this->route('workspace.projects.members.destroy', ['member' => $newMember]))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PROJECT_MEMBER_REMOVED->value]);
    }

    public function test_project_created_updated_and_deleted_each_record_an_audit_entry(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.projects.store', ['workspace' => $this->workspace]), ['name' => 'New Project'])
            ->assertRedirect();

        $newProject = Project::query()->where('name', 'New Project')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PROJECT_CREATED->value, 'project_id' => $newProject->id]);

        $this->actingAs($this->owner)
            ->put(route('workspace.projects.update', ['workspace' => $this->workspace, 'project' => $newProject]), [
                'name' => 'Renamed Project',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PROJECT_UPDATED->value]);

        $this->actingAs($this->owner)
            ->delete(route('workspace.projects.destroy', ['workspace' => $this->workspace, 'project' => $newProject]))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PROJECT_DELETED->value,
            'description' => "{$this->owner->name} deleted project \"Renamed Project\".",
        ]);
    }

    public function test_removing_a_workspace_member_and_changing_their_role_each_record_an_audit_entry(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('workspace.members.update', ['workspace' => $this->workspace, 'user' => $this->manager]), [
                'role' => UserRole::ADMIN->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::MEMBER_ROLE_CHANGED->value,
            'subject_id' => $this->manager->id,
        ]);

        $this->actingAs($this->owner)
            ->delete(route('workspace.members.destroy', ['workspace' => $this->workspace, 'user' => $this->manager]))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::MEMBER_REMOVED->value]);
    }
}
