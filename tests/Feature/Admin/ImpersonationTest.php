<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Support\Impersonation;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Platform Admin', 'is_super_admin' => true]);
        $this->owner = User::factory()->create(['name' => 'Ayesha Khan']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function viewAs(User $user): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.impersonate', $user));
    }

    public function test_a_super_admin_views_the_app_as_another_user(): void
    {
        $this->viewAs($this->owner)->assertRedirect(route('dashboard', ['workspace' => $this->workspace->slug], false));

        $this->assertAuthenticatedAs($this->owner);
        $this->assertSame($this->admin->id, session(Impersonation::SESSION_KEY));

        $this->get(route('dashboard', ['workspace' => $this->workspace->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->owner->id)->where('impersonation.user_name', 'Ayesha Khan'));

        $log = AuditLog::query()->where('action', AuditAction::ACCOUNT_IMPERSONATION_STARTED->value)->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($this->owner->id, $log->metadata['impersonated_user_id']);
    }

    public function test_returning_to_admin_restores_the_super_admin(): void
    {
        $this->viewAs($this->owner);

        $this->post(route('impersonation.stop'))->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($this->admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
        $this->get(route('admin.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('impersonation', null));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ACCOUNT_IMPERSONATION_ENDED->value)->count());
    }

    public function test_super_admins_and_yourself_cannot_be_viewed_as(): void
    {
        $other = User::factory()->create(['is_super_admin' => true]);

        $this->viewAs($other)->assertSessionHas('error');
        $this->assertAuthenticatedAs($this->admin);

        $this->viewAs($this->admin)->assertSessionHas('error');
        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::ACCOUNT_IMPERSONATION_STARTED->value)->count());
    }

    public function test_only_super_admins_can_start_viewing_as_someone(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post(route('admin.impersonate', $this->owner))->assertNotFound();
        $this->assertAuthenticatedAs($member);
    }

    public function test_while_viewing_as_someone_their_account_settings_are_locked(): void
    {
        $this->owner->forceFill(['password' => Hash::make('original-password')])->save();
        $this->viewAs($this->owner);

        $this->put(route('password.update'), ['current_password' => 'original-password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertSessionHas('error');
        $this->patch(route('profile.update'), ['name' => 'Changed', 'email' => 'changed@example.test'])->assertSessionHas('error');
        $this->delete(route('profile.destroy'), ['password' => 'original-password'])->assertSessionHas('error');

        $fresh = $this->owner->fresh();
        $this->assertTrue(Hash::check('original-password', $fresh->password));
        $this->assertSame('Ayesha Khan', $fresh->name);
        $this->assertModelExists($fresh);
    }

    public function test_a_stale_impersonation_session_signs_out_instead_of_escalating(): void
    {
        $this->viewAs($this->owner);
        $this->admin->forceFill(['is_super_admin' => false])->save();

        $this->post(route('impersonation.stop'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
