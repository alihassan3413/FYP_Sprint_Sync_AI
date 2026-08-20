<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class LandingRouteTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user, string $password = 'password'): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    public function test_a_super_admin_without_a_workspace_lands_on_the_admin_dashboard(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $admin = User::query()->where('email', 'superadmin@example.com')->firstOrFail();

        $this->assertNull($admin->current_workspace_id);

        $this->signIn($admin)
            ->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_ordinary_user_without_a_workspace_lands_on_the_home_page(): void
    {
        $user = User::factory()->create();

        $this->signIn($user)->assertRedirect(route('home', absolute: false));
    }

    public function test_a_user_lands_on_their_current_workspace_dashboard(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($user)->create();
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $this->signIn($user)
            ->assertRedirect(route('dashboard', ['workspace' => $workspace->slug], false));
    }

    public function test_a_null_current_workspace_falls_back_to_one_they_belong_to(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $this->assertNull($member->current_workspace_id);

        $this->signIn($member)
            ->assertRedirect(route('dashboard', ['workspace' => $workspace->slug], false));

        $this->assertSame($workspace->id, $member->refresh()->current_workspace_id);
    }

    public function test_a_stale_current_workspace_pointer_does_not_strand_the_user(): void
    {
        $owner = User::factory()->create();
        $joined = Workspace::factory()->ownedBy($owner)->create();

        $stranger = User::factory()->create();
        $gone = Workspace::factory()->ownedBy($stranger)->create();

        $member = User::factory()->create();
        $joined->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        /* Points at a workspace they are not a member of. */
        $member->forceFill(['current_workspace_id' => $gone->id])->save();

        $this->signIn($member)
            ->assertRedirect(route('dashboard', ['workspace' => $joined->slug], false));
    }

    public function test_signing_in_never_returns_an_error_status(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $admin = User::query()->where('email', 'superadmin@example.com')->firstOrFail();

        $this->assertSame(302, $this->signIn($admin)->getStatusCode());
    }
}
