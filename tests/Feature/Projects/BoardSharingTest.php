<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Data\BoardShareAccess;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectAccessRequest;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\BoardAccessRequestedNotification;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Trello-style share links: anyone with the link views the board read-only;
 * adding tasks needs an account, straight away on an "add" link or after a
 * manager approves a request on a "view" link.
 */
final class BoardSharingTest extends TestCase
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
        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Rocket Tasks']);
        Task::factory()->forProject($this->project)->create(['title' => 'ReThought New API']);
    }

    private function share(?BoardShareAccess $access, bool $regenerate = false, ?User $as = null): string
    {
        $this->actingAs($as ?? $this->owner)
            ->put(route('workspace.projects.sharing.update', [$this->workspace, $this->project]), ['access' => $access?->value, 'regenerate' => $regenerate])
            ->assertSessionHasNoErrors();

        return (string) $this->project->fresh()->share_token;
    }

    private function addTask(User $user): TestResponse
    {
        return $this->actingAs($user)->post(route('workspace.projects.tasks.store', [$this->workspace, $this->project]), ['title' => 'Agent license upload fails']);
    }

    public function test_anyone_with_a_view_link_sees_the_board_without_signing_in(): void
    {
        $token = $this->share(BoardShareAccess::View);

        $this->app['auth']->forgetGuards();
        $this->get(route('shared-board.show', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shared/board')
                ->where('project.name', 'Rocket Tasks')
                ->where('access', 'view')
                ->where('viewer', 'guest')
                ->where('projectUrl', null)
                ->where('tasks.0.title', 'ReThought New API')
                ->missing('tasks.0.comments')
                ->missing('tasks.0.attachments')
                ->has('columns', 3));
    }

    public function test_turning_sharing_off_or_replacing_the_link_closes_the_old_link(): void
    {
        $old = $this->share(BoardShareAccess::View);
        $new = $this->share(BoardShareAccess::View, regenerate: true);

        $this->assertNotSame($old, $new);
        $this->get(route('shared-board.show', $old))->assertNotFound();
        $this->get(route('shared-board.show', $new))->assertOk();

        $this->share(null);
        $this->get(route('shared-board.show', $new))->assertNotFound();

        /* Switching back on brings the same link back. */
        $this->assertSame($new, $this->share(BoardShareAccess::Add));
    }

    public function test_only_managers_change_sharing(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->project->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($member)
            ->put(route('workspace.projects.sharing.update', [$this->workspace, $this->project]), ['access' => 'add'])
            ->assertForbidden();

        $this->project->members()->updateExistingPivot($member->id, ['role' => ProjectRole::MANAGER->value]);
        $this->share(BoardShareAccess::Add, as: $member);
        $this->assertSame(BoardShareAccess::Add, $this->project->fresh()->share_access);
    }

    public function test_signing_in_from_the_link_comes_back_to_the_board(): void
    {
        $token = $this->share(BoardShareAccess::Add);
        $this->app['auth']->forgetGuards();

        $this->get(route('shared-board.sign-in', $token))->assertRedirect(route('login'));
        $this->assertSame(route('shared-board.sign-in', $token), session('url.intended'));
    }

    public function test_an_add_link_lets_a_signed_in_guest_add_tasks_straight_away(): void
    {
        $token = $this->share(BoardShareAccess::Add);
        $other = Project::factory()->forWorkspace($this->workspace)->create();
        $guest = User::factory()->create();

        $this->actingAs($guest)->post(route('shared-board.join', $token))
            ->assertRedirect(route('workspace.projects.show', ['workspace' => $this->workspace->slug, 'project' => $this->project->id, 'new_task' => 1]));

        $this->assertSame(UserRole::CLIENT, $this->workspace->roleFor($guest));
        $this->assertTrue($this->project->memberCanAddTasks($guest));
        $this->addTask($guest)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', ['project_id' => $this->project->id, 'title' => 'Agent license upload fails']);

        /* A board guest sees nothing else in the workspace. */
        $this->actingAs($guest)->get(route('workspace.projects.show', [$this->workspace, $other]))->assertForbidden();
        $this->actingAs($guest)->get(route('shared-board.show', $token))->assertInertia(fn (Assert $page) => $page->where('viewer', 'can_add'));

        /* Joining again changes nothing. */
        $this->actingAs($guest)->post(route('shared-board.join', $token))->assertRedirect();
        $this->assertSame(1, $this->project->members()->whereKey($guest->id)->count());
    }

    public function test_a_view_link_needs_a_managers_approval_to_add_tasks(): void
    {
        Notification::fake();
        $token = $this->share(BoardShareAccess::View);
        $guest = User::factory()->create(['name' => 'Andreas Lindner']);

        $this->actingAs($guest)->post(route('shared-board.join', $token))->assertForbidden();
        $this->addTask($guest)->assertNotFound();

        $this->actingAs($guest)->post(route('shared-board.request', $token))->assertSessionHas('success');
        $this->actingAs($guest)->post(route('shared-board.request', $token));
        Notification::assertSentToTimes($this->owner, BoardAccessRequestedNotification::class, 1);
        $this->actingAs($guest)->get(route('shared-board.show', $token))->assertInertia(fn (Assert $page) => $page->where('viewer', 'pending'));

        $request = ProjectAccessRequest::query()->sole();
        $this->actingAs($this->owner)->get(route('workspace.projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('sharing.requests.0.name', 'Andreas Lindner'));

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.sharing.requests.decide', [$this->workspace, $this->project, $request->id]), ['decision' => 'approve'])
            ->assertSessionHas('success');

        $this->assertSame('approved', $request->fresh()->status);
        $this->addTask($guest)->assertSessionHasNoErrors();
    }

    public function test_a_declined_guest_can_ask_again(): void
    {
        Notification::fake();
        $token = $this->share(BoardShareAccess::View);
        $guest = User::factory()->create();
        $this->actingAs($guest)->post(route('shared-board.request', $token));
        $request = ProjectAccessRequest::query()->sole();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.sharing.requests.decide', [$this->workspace, $this->project, $request->id]), ['decision' => 'decline']);
        $this->assertFalse($this->project->hasMember($guest));
        $this->actingAs($guest)->get(route('shared-board.show', $token))->assertInertia(fn (Assert $page) => $page->where('viewer', 'declined'));

        $this->actingAs($guest)->post(route('shared-board.request', $token));
        $this->assertSame('pending', $request->fresh()->status);
        Notification::assertSentToTimes($this->owner, BoardAccessRequestedNotification::class, 2);
    }

    public function test_only_managers_decide_requests_on_their_own_board(): void
    {
        $token = $this->share(BoardShareAccess::View);
        $guest = User::factory()->create();
        $this->actingAs($guest)->post(route('shared-board.request', $token));
        $request = ProjectAccessRequest::query()->sole();
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();

        $this->actingAs($guest)
            ->post(route('workspace.projects.sharing.requests.decide', [$this->workspace, $this->project, $request->id]), ['decision' => 'approve'])
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->post(route('workspace.projects.sharing.requests.decide', [$this->workspace, $otherProject, $request->id]), ['decision' => 'approve'])
            ->assertNotFound();
        $this->assertSame('pending', $request->fresh()->status);
    }
}
