<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\TestCase;

/**
 * Everyone tracks their own time; only the owner sees and manages the team's.
 */
final class TimeAccessTest extends TestCase
{
    use BuildsTimeTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->setUpTimeTeam();
    }

    private function joined(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    private function recordUsmanTime(): TimeEntry
    {
        $this->startTimer(['task' => $this->task->public_id]);
        $this->travel(2)->hours();
        $this->timer('stop');

        return TimeEntry::query()->sole();
    }

    public function test_a_member_sees_their_own_time_page(): void
    {
        $entry = $this->recordUsmanTime();

        $this->actingAs($this->member)
            ->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/index')
                ->where('canManageTeam', false)
                ->where('isSelf', true)
                ->where('viewing.public_id', $this->usman()->public_id)
                ->where('people', [])
                ->where('totals.today', 7200)
                ->where('entries.0.public_id', $entry->public_id)
                ->where('entries.0.task_title', 'Flood Quote Workflow')
                ->where('entries.0.created_by', null)
                ->missing('entries.0.id')
                ->where('projects.0.public_id', $this->project->public_id)
                ->missing('projects.0.id'));
    }

    public function test_a_member_cannot_look_at_someone_elses_time(): void
    {
        $this->actingAs($this->member)
            ->get(route('workspace.time.index', [$this->workspace, 'person' => $this->team['Faheem']->public_id]))
            ->assertForbidden();
    }

    public function test_the_owner_sees_anyones_time(): void
    {
        $this->recordUsmanTime();

        $this->actingAs($this->owner)
            ->get(route('workspace.time.index', [$this->workspace, 'person' => $this->usman()->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManageTeam', true)
                ->where('isSelf', false)
                ->where('viewing.name', 'Muhammad Usman Ghani')
                ->where('totals.month', 7200)
                ->has('people', 6));
    }

    public function test_an_admin_tracks_their_own_time_but_sees_no_one_elses(): void
    {
        $admin = $this->joined(UserRole::ADMIN);

        $this->actingAs($admin)->get(route('workspace.time.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManageTeam', false)->where('viewing', null));
        $this->actingAs($admin)->get(route('workspace.time.index', [$this->workspace, 'person' => $this->usman()->public_id]))->assertForbidden();
        $this->actingAs($admin)->get(route('workspace.time.previous-hours.create', $this->workspace))->assertForbidden();
        $this->actingAs($admin)->post(route('workspace.time.entries.void', [$this->workspace, $this->recordUsmanTime()]), ['reason' => 'x'])->assertForbidden();
    }

    public function test_client_guests_have_no_time_tracking(): void
    {
        $guest = $this->joined(UserRole::CLIENT);

        $this->actingAs($guest)->get(route('workspace.time.index', $this->workspace))->assertForbidden();
        $this->startTimer([], $guest)->assertForbidden();
        $this->actingAs($guest)->getJson(route('workspace.time.timer.current', $this->workspace))->assertForbidden();
    }

    public function test_the_time_link_is_shown_to_members_but_not_guests(): void
    {
        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('navigation.time', true));

        $guest = $this->joined(UserRole::CLIENT);
        $this->actingAs($guest)->get(route('dashboard', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('navigation.time', false));
    }

    public function test_other_workspaces_get_not_found(): void
    {
        $entry = $this->recordUsmanTime();
        $outsider = User::factory()->create();
        $other = Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.time.index', $this->workspace))->assertNotFound();
        $this->actingAs($outsider)->postJson(route('workspace.time.timer.start', $this->workspace), ['project' => $this->project->public_id, 'operation_key' => $this->key()])->assertNotFound();

        /* The owner of another workspace cannot reach this entry through their own workspace either. */
        $this->actingAs($outsider)->post(route('workspace.time.entries.void', [$other, $entry]), ['reason' => 'x'])->assertNotFound();
        $this->actingAs($outsider)->get(route('workspace.time.index', [$other, 'person' => $this->usman()->public_id]))->assertNotFound();
        $this->assertNull($entry->fresh()->voided_at);
    }

    public function test_numeric_ids_are_never_accepted_in_urls(): void
    {
        $entry = $this->recordUsmanTime();

        $this->actingAs($this->owner)->post(route('workspace.time.entries.void', [$this->workspace, 'timeEntry' => $entry->id]), ['reason' => 'x'])->assertNotFound();
    }

    public function test_time_audit_entries_are_owner_only(): void
    {
        $this->recordUsmanTime();
        $admin = $this->joined(UserRole::ADMIN);

        $this->assertSame(AuditAction::TIME_CATEGORY, AuditAction::TIME_TIMER_STARTED->category());

        $this->actingAs($admin)->get(route('workspace.audit.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => ! collect($categories)->contains(AuditAction::TIME_CATEGORY)));
        $this->actingAs($this->owner)->get(route('workspace.audit.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => collect($categories)->contains(AuditAction::TIME_CATEGORY)));
    }

    public function test_the_previous_hours_page_lists_the_team(): void
    {
        $this->actingAs($this->owner)->get(route('workspace.time.previous-hours.create', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/previous-hours')
                ->has('people', 6)
                ->missing('people.0.id')
                ->where('projects.0.tasks.0.title', 'Flood Quote Workflow'));
    }
}
