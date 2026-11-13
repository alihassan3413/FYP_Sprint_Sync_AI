<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Models\User;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\TestCase;

/**
 * The owner can hand team time to an admin or a "Manager" role: seeing it,
 * editing it and approving flagged time are separate switches. Editing or
 * approving implies seeing. Time settings stay owner-only.
 */
final class DelegatedTimeAccessTest extends TestCase
{
    use BuildsTimeTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->setUpTimeTeam();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function withRole(UserRole $base, array $permissions): User
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'permissions' => array_fill_keys($permissions, true),
        ]);
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $base->value, 'workspace_role_id' => $role->id]);

        return $user;
    }

    private function recordUsmanTime(): TimeEntry
    {
        $this->startTimer(['task' => $this->task->public_id]);
        $this->travel(2)->hours();
        $this->timer('stop');

        return TimeEntry::query()->sole();
    }

    private function openReview(): TimeReview
    {
        return TimeReview::query()->create([
            'workspace_id' => $this->workspace->id,
            'person_id' => $this->usman()->id,
            'kind' => ReviewKind::Activity,
            'work_date' => '2026-10-12',
            'summary' => 'Unusually repetitive activity',
        ]);
    }

    private function usmanPage(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('workspace.time.index', [$this->workspace, 'person' => $this->usman()->public_id]));
    }

    public function test_a_viewer_sees_the_team_but_cannot_change_or_decide_anything(): void
    {
        $entry = $this->recordUsmanTime();
        $this->openReview();
        $viewer = $this->withRole(UserRole::ADMIN, ['time.view']);

        $this->actingAs($viewer)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'team')
                ->where('canViewTeam', true)
                ->where('canManageTeam', false)
                ->where('canApproveTime', false)
                ->where('canConfigureTime', false)
                ->has('team', 6)
                ->has('people', 6));
        $this->usmanPage($viewer)
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewing.name', 'Muhammad Usman Ghani')
                ->where('totals.today', 7200)
                ->where('reviews', [])
                ->where('nextNeedingReview', null));

        $this->actingAs($viewer)->postJson(route('workspace.time.entries.store', $this->workspace), [
            'person' => $this->usman()->public_id, 'project' => $this->project->public_id, 'date' => '2026-10-12', 'hours' => '1', 'idempotency_key' => $this->key(),
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('workspace.time.entries.void', [$this->workspace, $entry]), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->get(route('workspace.time.previous-hours.create', $this->workspace))->assertForbidden();
        $this->actingAs($viewer)->post(route('workspace.time.reviews.resolve', [$this->workspace, TimeReview::query()->sole()->public_id]), ['decision' => 'no_concern'])->assertForbidden();
        $this->assertSame('open', TimeReview::query()->sole()->status);
    }

    public function test_an_editor_adds_and_corrects_anyones_time(): void
    {
        $tracked = $this->recordUsmanTime();
        $manager = $this->withRole(UserRole::MEMBER, ['time.edit']);

        $this->usmanPage($manager)->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewTeam', true)->where('canManageTeam', true));

        $this->actingAs($manager)->postJson(route('workspace.time.entries.store', $this->workspace), [
            'person' => $this->team['Faheem']->public_id, 'project' => $this->project->public_id, 'date' => '2026-10-12', 'hours' => '1.5', 'idempotency_key' => $this->key(),
        ])->assertSuccessful();
        $this->assertSame(5400, (int) TimeEntry::query()->where('person_id', $this->team['Faheem']->id)->sum('duration_seconds'));

        $this->actingAs($manager)->post(route('workspace.time.entries.adjust', [$this->workspace, $tracked]), ['hours' => '-0.5', 'reason' => 'Forgot to stop', 'idempotency_key' => $this->key()])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, TimeEntry::query()->where('source', 'adjustment')->count());
        $this->actingAs($manager)->get(route('workspace.time.previous-hours.create', $this->workspace))->assertOk();

        $this->openReview();
        $this->actingAs($manager)->post(route('workspace.time.reviews.resolve', [$this->workspace, TimeReview::query()->sole()->public_id]), ['decision' => 'no_concern'])->assertForbidden();
    }

    public function test_an_approver_decides_flagged_time(): void
    {
        $entry = $this->recordUsmanTime();
        $review = $this->openReview();
        $approver = $this->withRole(UserRole::MEMBER, ['time.approve']);

        $this->usmanPage($approver)
            ->assertInertia(fn (Assert $page) => $page->where('canApproveTime', true)->where('canManageTeam', false)->where('reviews.0.public_id', $review->public_id));

        $this->actingAs($approver)->post(route('workspace.time.reviews.resolve', [$this->workspace, $review->public_id]), ['decision' => 'follow_up', 'note' => 'Checked with Usman'])
            ->assertSessionHasNoErrors();
        $this->assertSame('resolved', $review->fresh()->status);
        $this->assertSame($approver->id, $review->fresh()->resolved_by);

        $this->actingAs($approver)->post(route('workspace.time.entries.void', [$this->workspace, $entry]), ['reason' => 'x'])->assertForbidden();
    }

    public function test_time_settings_stay_with_the_owner_even_with_every_time_permission(): void
    {
        $manager = $this->withRole(UserRole::ADMIN, ['time.view', 'time.edit', 'time.approve']);

        $this->actingAs($manager)->get(route('workspace.time.settings.edit', $this->workspace))->assertForbidden();
        $this->actingAs($manager)->put(route('workspace.time.settings.policy', $this->workspace), [])->assertForbidden();
        $this->actingAs($this->owner)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewTeam', true)
                ->where('canManageTeam', true)
                ->where('canApproveTime', true)
                ->where('canConfigureTime', true));
    }

    public function test_a_client_role_never_gets_team_time(): void
    {
        $guest = $this->withRole(UserRole::CLIENT, ['time.view', 'time.edit', 'time.approve']);

        $this->usmanPage($guest)->assertForbidden();
    }

    public function test_the_role_editor_offers_and_saves_the_time_permissions(): void
    {
        $role = WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->owner)->get(route('workspace.roles.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('availablePermissions', fn ($keys) => collect($keys)->intersect(['time.view', 'time.edit', 'time.approve'])->count() === 3));

        $this->actingAs($this->owner)->put(route('workspace.roles.update', [$this->workspace, $role]), [
            'name' => 'Manager',
            'permissions' => ['time.view' => true, 'time.approve' => true],
        ])->assertRedirect();

        $role->refresh();
        $this->assertTrue($role->grants('time.view'));
        $this->assertTrue($role->grants('time.approve'));
        $this->assertFalse($role->grants('time.edit'));
    }
}
