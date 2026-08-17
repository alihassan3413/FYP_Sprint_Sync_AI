<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditLogViewTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $manager;

    private User $plainMember;

    private Project $managedProject;

    private Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->manager = User::factory()->create();
        $this->workspace->users()->attach($this->manager->id, ['role' => UserRole::MEMBER->value]);

        $this->plainMember = User::factory()->create();
        $this->workspace->users()->attach($this->plainMember->id, ['role' => UserRole::MEMBER->value]);

        $this->managedProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Managed Project']);
        $this->managedProject->members()->attach($this->manager->id, ['role' => ProjectRole::MANAGER->value]);

        $this->otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Other Project']);

        AuditLog::factory()->forWorkspace($this->workspace)->byUser($this->owner)
            ->action(AuditAction::WORKSPACE_RENAMED)
            ->create(['project_id' => null, 'description' => 'Workspace-level entry']);

        AuditLog::factory()->forWorkspace($this->workspace)->forProject($this->managedProject)->byUser($this->owner)
            ->action(AuditAction::TASK_CREATED)
            ->create(['description' => 'Managed project entry']);

        AuditLog::factory()->forWorkspace($this->workspace)->forProject($this->otherProject)->byUser($this->owner)
            ->action(AuditAction::MEETING_SCHEDULED)
            ->create(['description' => 'Other project entry']);
    }

    private function auditRoute(array $params = []): string
    {
        return route('workspace.audit.index', array_merge(['workspace' => $this->workspace], $params));
    }

    public function test_owner_sees_every_entry_including_workspace_level_ones(): void
    {
        $this->actingAs($this->owner)
            ->get($this->auditRoute())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entries.total', 3)->where('isAdmin', true));
    }

    public function test_project_manager_only_sees_entries_for_projects_they_manage(): void
    {
        $this->actingAs($this->manager)
            ->get($this->auditRoute())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 1)
                ->where('entries.data.0.description', 'Managed project entry')
                ->where('isAdmin', false));
    }

    public function test_a_plain_project_member_is_denied_access(): void
    {
        $this->managedProject->members()->attach($this->plainMember->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->plainMember)
            ->get($this->auditRoute())
            ->assertForbidden();
    }

    public function test_a_workspace_member_with_no_managed_projects_is_denied_access(): void
    {
        $this->actingAs($this->plainMember)
            ->get($this->auditRoute())
            ->assertForbidden();
    }

    public function test_a_non_member_of_the_workspace_cannot_view_its_audit_log(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get($this->auditRoute())
            ->assertNotFound();
    }

    public function test_cross_workspace_data_never_leaks(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        AuditLog::factory()->forWorkspace($otherWorkspace)->byUser($otherOwner)
            ->action(AuditAction::WORKSPACE_RENAMED)
            ->create();

        $this->actingAs($this->owner)
            ->get($this->auditRoute())
            ->assertInertia(fn ($page) => $page->where('entries.total', 3));
    }

    public function test_filtering_by_category_scopes_the_results(): void
    {
        $this->actingAs($this->owner)
            ->get($this->auditRoute(['category' => 'Tasks']))
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 1)
                ->where('entries.data.0.description', 'Managed project entry'));
    }

    public function test_filtering_by_project_scopes_the_results(): void
    {
        $this->actingAs($this->owner)
            ->get($this->auditRoute(['project_id' => $this->otherProject->id]))
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 1)
                ->where('entries.data.0.description', 'Other project entry'));
    }

    public function test_filtering_by_user_scopes_the_results(): void
    {
        $otherActor = User::factory()->create();
        $this->workspace->users()->attach($otherActor->id, ['role' => UserRole::MEMBER->value]);

        AuditLog::factory()->forWorkspace($this->workspace)->forProject($this->managedProject)->byUser($otherActor)
            ->action(AuditAction::TASK_UPDATED)
            ->create(['description' => 'Entry by another actor']);

        $this->actingAs($this->owner)
            ->get($this->auditRoute(['user_id' => $otherActor->id]))
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 1)
                ->where('entries.data.0.description', 'Entry by another actor'));
    }

    public function test_filtering_by_date_range_scopes_the_results(): void
    {
        AuditLog::factory()->forWorkspace($this->workspace)->forProject($this->managedProject)->byUser($this->owner)
            ->action(AuditAction::TASK_UPDATED)
            ->create(['description' => 'Old entry', 'created_at' => now()->subDays(30)]);

        $this->actingAs($this->owner)
            ->get($this->auditRoute(['from' => now()->subDays(5)->toDateString()]))
            ->assertInertia(fn ($page) => $page->where('entries.total', 3));
    }
}
