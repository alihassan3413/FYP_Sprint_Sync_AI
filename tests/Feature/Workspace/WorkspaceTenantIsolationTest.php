<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class WorkspaceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->outsider = User::factory()->create();
    }

    public function test_outsider_cannot_view_the_dashboard(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('dashboard', $this->workspace))
            ->assertNotFound();
    }

    public function test_outsider_cannot_view_workspace_settings(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('workspace.settings', $this->workspace))
            ->assertNotFound();
    }

    public function test_outsider_cannot_view_roles(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('workspace.roles.index', $this->workspace))
            ->assertNotFound();
    }

    public function test_outsider_cannot_view_the_team_roster(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('workspace.teams.index', $this->workspace))
            ->assertNotFound();
    }

    public function test_outsider_cannot_invite_into_the_workspace(): void
    {
        Mail::fake();

        $this->actingAs($this->outsider)
            ->post(route('workspace.invitations.store', $this->workspace), [
                'email' => 'attacker@example.com',
                'role' => UserRole::ADMIN->value,
            ])
            ->assertNotFound();

        $this->assertSame(0, $this->workspace->invitations()->count());
    }

    public function test_outsider_cannot_switch_into_the_workspace(): void
    {
        $this->actingAs($this->outsider)
            ->post(route('workspace.switch', $this->workspace))
            ->assertNotFound();

        $this->assertNull($this->outsider->refresh()->current_workspace_id);
    }

    public function test_member_can_view_the_dashboard(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($member)
            ->get(route('dashboard', $this->workspace))
            ->assertOk();
    }

    public function test_member_cannot_invite_but_admin_can(): void
    {
        Mail::fake();

        $member = User::factory()->create();
        $admin = User::factory()->create();

        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $this->workspace->users()->attach($admin->id, ['role' => UserRole::ADMIN->value]);

        $this->actingAs($member)
            ->post(route('workspace.invitations.store', $this->workspace), [
                'email' => 'nope@example.com',
                'role' => UserRole::MEMBER->value,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('workspace.invitations.store', $this->workspace), [
                'email' => 'yes@example.com',
                'role' => UserRole::MEMBER->value,
            ])
            ->assertRedirect();

        $this->assertSame(1, $this->workspace->invitations()->count());
    }

    public function test_member_cannot_create_or_delete_roles(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($member)
            ->post(route('workspace.roles.store', $this->workspace), ['name' => 'Designer'])
            ->assertForbidden();

        $this->assertSame(0, $this->workspace->roles()->count());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard', $this->workspace))->assertRedirect(route('login'));
    }
}
