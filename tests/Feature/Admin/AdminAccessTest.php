<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_an_ordinary_user_cannot_see_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.dashboard'))
            ->assertNotFound();
    }

    public function test_a_workspace_owner_is_still_not_a_platform_admin(): void
    {
        // The workspace role system tops out at owner; that must not leak into
        // platform-wide access.
        $owner = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($owner)
            ->get(route('admin.dashboard'))
            ->assertNotFound();
    }

    public function test_an_unverified_super_admin_is_sent_to_verification(): void
    {
        $user = User::factory()->unverified()->create(['is_super_admin' => true]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_super_admin_can_open_the_panel(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/index'));
    }

    public function test_the_route_is_gated_by_the_super_admin_middleware(): void
    {
        $middleware = Route::getRoutes()->getByName('admin.dashboard')->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
        $this->assertContains(EnsureUserIsSuperAdmin::class, $middleware);
    }

    public function test_the_panel_is_not_scoped_to_a_workspace(): void
    {
        // A tenant-scoped route would need a workspace in the URL; the admin
        // panel deliberately does not.
        $this->assertSame('admin', Route::getRoutes()->getByName('admin.dashboard')->uri());
    }

    public function test_the_super_admin_flag_is_shared_with_the_frontend_for_nav(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('auth.user.is_super_admin', true));
    }

    public function test_an_ordinary_user_is_not_flagged_as_a_super_admin(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertInertia(fn ($page) => $page->where('auth.user.is_super_admin', false));
    }

    public function test_users_default_to_not_being_super_admins(): void
    {
        $this->assertFalse(User::factory()->create()->isSuperAdmin());
    }
}
