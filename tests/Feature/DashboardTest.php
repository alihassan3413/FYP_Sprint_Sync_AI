<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $workspace = Workspace::factory()->ownedBy(User::factory()->create())->create();

        $this->get(route('dashboard', $workspace))->assertRedirect(route('login'));
    }

    public function test_a_member_can_visit_the_dashboard(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $this->actingAs($owner)
            ->get(route('dashboard', $workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('workspaceMeta.name', $workspace->name)
                ->where('pendingInvitesCount', 0)
                ->has('members', 1)
                ->where('onboarding.workspace_created', true)
                ->where('onboarding.first_member_invited', false));
    }

    public function test_the_dashboard_counts_pending_invitations(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $workspace->invitations()->create([
            'email' => 'pending@example.com',
            'role' => 'member',
            'token' => 'pending-token',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard', $workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingInvitesCount', 1)
                ->where('onboarding.first_member_invited', true));
    }
}
