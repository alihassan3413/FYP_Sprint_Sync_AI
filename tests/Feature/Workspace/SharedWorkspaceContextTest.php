<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Services\WorkspaceService;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The front end builds every workspace-scoped URL from the shared `workspace.current`
 * prop. When it is null, `workspaceRoute()` has no slug to work with and every
 * button on the page becomes a dead link — so this prop must never be null for a
 * user who belongs to a workspace, whatever state `current_workspace_id` is in.
 */
final class SharedWorkspaceContextTest extends TestCase
{
    use RefreshDatabase;

    private function shared(User $user): ?array
    {
        return app(WorkspaceService::class)->inertiaFor($user->refresh());
    }

    public function test_a_null_current_workspace_id_still_yields_a_current_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $this->assertNull($member->current_workspace_id);

        $shared = $this->shared($member);

        $this->assertNotNull($shared['current'], 'A null current workspace makes every link on the page dead.');
        $this->assertSame($workspace->slug, $shared['current']['slug']);
    }

    public function test_a_stale_pointer_falls_back_rather_than_returning_null(): void
    {
        $stranger = User::factory()->create();
        $gone = Workspace::factory()->ownedBy($stranger)->create();

        $owner = User::factory()->create();
        $joined = Workspace::factory()->ownedBy($owner)->create();

        /* Points at a workspace they are not a member of. */
        $owner->forceFill(['current_workspace_id' => $gone->id])->save();

        $shared = $this->shared($owner);

        $this->assertNotNull($shared['current']);
        $this->assertSame($joined->slug, $shared['current']['slug']);
    }

    public function test_the_real_current_workspace_still_wins(): void
    {
        $owner = User::factory()->create();
        $first = Workspace::factory()->ownedBy($owner)->create();
        $second = Workspace::factory()->ownedBy($owner)->create();

        $owner->forceFill(['current_workspace_id' => $second->id])->save();

        $this->assertSame($second->slug, $this->shared($owner)['current']['slug']);
        $this->assertNotSame($first->slug, $this->shared($owner)['current']['slug']);
    }

    public function test_someone_in_no_workspace_has_no_current(): void
    {
        $shared = $this->shared(User::factory()->create());

        $this->assertNull($shared['current']);
        $this->assertSame([], $shared['available']);
    }
}
