<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($user)->create();
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', $workspace, absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_an_authenticated_user_visiting_a_guest_route_is_sent_to_their_workspace_dashboard()
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($user)->create();
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect(route('dashboard', $workspace));
    }

    public function test_an_authenticated_user_without_a_workspace_visiting_a_guest_route_is_sent_home()
    {
        $user = User::factory()->create(['current_workspace_id' => null]);

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect(route('home'));
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
