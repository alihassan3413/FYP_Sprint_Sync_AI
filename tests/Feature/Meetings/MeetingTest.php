<?php

declare(strict_types=1);

namespace Tests\Feature\Meetings;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MeetingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->admin = User::factory()->create();
        $this->workspace->users()->attach($this->admin->id, ['role' => UserRole::ADMIN->value]);
        $this->member = User::factory()->create();
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);
        $this->project = Project::factory()->forWorkspace($this->workspace)->create();
    }

    private function meetingRoute(string $name, ?Meeting $meeting = null, array $extra = []): string
    {
        $params = array_merge(['workspace' => $this->workspace, 'project' => $this->project], $extra);

        if ($meeting !== null) {
            $params['meeting'] = $meeting;
        }

        return route($name, $params);
    }

    public function test_an_owner_can_schedule_a_meeting(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Sprint planning',
                'description' => 'Plan the next sprint.',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 45,
                'meeting_link' => 'https://meet.example.com/sprint-planning',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('meetings', [
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'title' => 'Sprint planning',
            'duration_minutes' => 45,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_an_admin_can_schedule_update_and_delete_a_meeting_without_project_membership(): void
    {
        $this->assertFalse($this->project->hasMember($this->admin));

        $this->actingAs($this->admin)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Admin meeting',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $meeting = Meeting::query()->where('title', 'Admin meeting')->firstOrFail();

        $this->actingAs($this->admin)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'Admin meeting updated',
                'scheduled_at' => '2026-09-02 10:00:00',
                'duration_minutes' => 45,
            ])
            ->assertRedirect();

        $this->assertSame('Admin meeting updated', $meeting->fresh()->title);

        $this->actingAs($this->admin)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting))
            ->assertRedirect();

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_the_authenticated_user_is_recorded_as_the_creator(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Retro',
                'scheduled_at' => '2026-09-02 15:00:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $meeting = Meeting::query()->where('title', 'Retro')->firstOrFail();

        $this->assertSame($this->owner->id, $meeting->created_by);
    }

    public function test_the_title_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertSessionHasErrors('title');

        $this->assertSame(0, $this->project->meetings()->count());
    }

    public function test_the_scheduled_at_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'No date',
                'duration_minutes' => 30,
            ])
            ->assertSessionHasErrors('scheduled_at');
    }

    public function test_the_duration_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'No duration',
                'scheduled_at' => '2026-09-01 10:00:00',
            ])
            ->assertSessionHasErrors('duration_minutes');
    }

    public function test_the_meeting_link_must_be_a_valid_url(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Bad link',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
                'meeting_link' => 'not-a-url',
            ])
            ->assertSessionHasErrors('meeting_link');
    }

    public function test_a_meeting_can_be_scheduled_without_a_link(): void
    {
        $this->actingAs($this->owner)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'No link yet',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('meetings', ['title' => 'No link yet', 'meeting_link' => null]);
    }

    public function test_an_unassigned_workspace_member_cannot_schedule_a_meeting(): void
    {
        $this->actingAs($this->member)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Not allowed',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->project->meetings()->count());
    }

    public function test_an_owner_can_update_a_meeting(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create(['title' => 'Old title']);

        $this->actingAs($this->owner)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'New title',
                'description' => 'Updated agenda.',
                'scheduled_at' => '2026-10-01 09:00:00',
                'duration_minutes' => 60,
                'meeting_link' => 'https://meet.example.com/updated',
            ])
            ->assertRedirect();

        $meeting->refresh();

        $this->assertSame('New title', $meeting->title);
        $this->assertSame('Updated agenda.', $meeting->description);
        $this->assertSame(60, $meeting->duration_minutes);
        $this->assertSame('https://meet.example.com/updated', $meeting->meeting_link);
    }

    public function test_an_unassigned_workspace_member_cannot_update_a_meeting(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create(['title' => 'Untouchable']);

        $this->actingAs($this->member)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'Hijacked',
                'scheduled_at' => '2026-10-01 09:00:00',
                'duration_minutes' => 60,
            ])
            ->assertForbidden();

        $this->assertSame('Untouchable', $meeting->fresh()->title);
    }

    public function test_an_owner_can_delete_a_meeting(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create();

        $this->actingAs($this->owner)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_an_unassigned_workspace_member_cannot_delete_a_meeting(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create();

        $this->actingAs($this->member)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting))
            ->assertForbidden();

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    public function test_an_unassigned_workspace_member_cannot_view_project_meetings(): void
    {
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create();

        $this->assertFalse($this->project->hasMember($this->member));

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertForbidden();
    }

    public function test_a_project_manager_can_schedule_update_and_delete_meetings_for_their_project(): void
    {
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Manager meeting',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertRedirect();

        $meeting = Meeting::query()->where('title', 'Manager meeting')->firstOrFail();

        $this->actingAs($this->member)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $meeting), [
                'title' => 'Manager meeting updated',
                'scheduled_at' => '2026-09-02 10:00:00',
                'duration_minutes' => 45,
            ])
            ->assertRedirect();

        $this->assertSame('Manager meeting updated', $meeting->fresh()->title);

        $this->actingAs($this->member)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $meeting))
            ->assertRedirect();

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_a_project_manager_of_another_project_cannot_manage_this_project_meetings(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $otherProject->members()->attach($this->member->id, ['role' => ProjectRole::MANAGER->value]);

        $this->actingAs($this->member)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Not allowed',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertForbidden();
    }

    public function test_a_project_member_can_view_but_not_manage_meetings_for_their_project(): void
    {
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create();
        Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create();
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/show')
                ->has('meetings', 2)
                ->where('canManageMeetings', false));

        $this->actingAs($this->member)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Not allowed',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertForbidden();
    }

    public function test_a_project_member_of_another_project_cannot_view_this_projects_meetings(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $otherProject->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($this->member)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertForbidden();
    }

    public function test_the_project_show_page_reports_meeting_management_for_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/show')
                ->where('canManageMeetings', true));
    }

    public function test_an_outsider_cannot_schedule_a_meeting(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post($this->meetingRoute('workspace.projects.meetings.store'), [
                'title' => 'Nope',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertNotFound();
    }

    public function test_a_meeting_from_another_project_cannot_be_updated_through_this_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $foreignMeeting = Meeting::factory()->forProject($otherProject)->createdBy($this->owner)->create(['title' => 'Foreign']);

        $this->actingAs($this->owner)
            ->put($this->meetingRoute('workspace.projects.meetings.update', $foreignMeeting), [
                'title' => 'Hijacked',
                'scheduled_at' => '2026-09-01 10:00:00',
                'duration_minutes' => 30,
            ])
            ->assertNotFound();

        $this->assertSame('Foreign', $foreignMeeting->fresh()->title);
    }

    public function test_a_meeting_from_another_workspace_cannot_be_reached_through_this_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $otherProject = Project::factory()->forWorkspace($otherWorkspace)->create();
        $foreignMeeting = Meeting::factory()->forProject($otherProject)->createdBy($this->owner)->create();

        $this->actingAs($this->owner)
            ->delete($this->meetingRoute('workspace.projects.meetings.destroy', $foreignMeeting))
            ->assertNotFound();

        $this->assertDatabaseHas('meetings', ['id' => $foreignMeeting->id]);
    }

    public function test_deleting_a_project_deletes_its_meetings(): void
    {
        $meeting = Meeting::factory()->forProject($this->project)->createdBy($this->owner)->create();

        $this->project->delete();

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }
}
