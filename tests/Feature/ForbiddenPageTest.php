<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A full page load that is forbidden renders resources/views/errors/403.blade.php.
 * (Inertia visits get a toast instead; that path is untouched.)
 */
final class ForbiddenPageTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($owner)->create();
    }

    private function member(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    public function test_an_admin_opening_clients_gets_a_real_403_page(): void
    {
        Client::factory()->rocketFlood()->for($this->workspace)->create(['tax_id' => 'TAX-123']);

        $this->actingAs($this->member(UserRole::ADMIN))
            ->get(route('workspace.clients.index', $this->workspace))
            ->assertForbidden()
            ->assertSee('Error 403')
            ->assertSee("You don't have access to this page.", false)
            ->assertSee('Go to your dashboard')
            ->assertSee(route('dashboard', $this->workspace, false), false)
            ->assertDontSee('RocketFlood')
            ->assertDontSee('billing@rocketflood.com')
            ->assertDontSee('TAX-123');
    }

    public function test_other_forbidden_pages_use_the_same_403_page(): void
    {
        $this->actingAs($this->member(UserRole::MEMBER))
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertForbidden()
            ->assertSee("You don't have access to this page.", false)
            ->assertSee('Go to your dashboard');
    }

    public function test_a_go_back_link_is_offered_when_there_is_somewhere_to_go_back_to(): void
    {
        $member = $this->member(UserRole::MEMBER);

        $this->actingAs($member)
            ->from(route('dashboard', $this->workspace))
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertForbidden()
            ->assertSee('Go back');
    }

    public function test_no_go_back_link_when_the_page_was_opened_directly(): void
    {
        $this->actingAs($this->member(UserRole::MEMBER))
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertDontSee('Go back');
    }
}
