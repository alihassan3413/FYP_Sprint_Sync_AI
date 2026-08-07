<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PendingInvitationLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_user_joins_after_logging_in_from_an_invitation_link(): void
    {
        $invitee = User::factory()->create(['email' => 'existing@example.com']);
        $ownWorkspace = Workspace::factory()->ownedBy($invitee)->create();
        $invitee->forceFill(['current_workspace_id' => $ownWorkspace->id])->save();

        $host = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($host)->create();

        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $host->id,
            'email' => 'existing@example.com',
        ]);

        $this->get(route('workspace.invitations.accept', $invitation->token))
            ->assertRedirect(route('login'))
            ->assertSessionHas('pending_invitation_token', $invitation->token);

        $this->post('/login', ['email' => 'existing@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard', $workspace));

        $this->assertTrue($workspace->fresh()->hasMember($invitee));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_an_expired_pending_invitation_sends_the_user_to_their_own_workspace(): void
    {
        $invitee = User::factory()->create(['email' => 'existing@example.com']);
        $ownWorkspace = Workspace::factory()->ownedBy($invitee)->create();
        $invitee->forceFill(['current_workspace_id' => $ownWorkspace->id])->save();

        $host = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($host)->create();

        $invitation = WorkspaceInvitation::factory()->expired()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $host->id,
            'email' => 'existing@example.com',
        ]);

        $this->withSession(['pending_invitation_token' => $invitation->token])
            ->post('/login', ['email' => 'existing@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard', $ownWorkspace))
            ->assertSessionHas('error');

        $this->assertFalse($workspace->fresh()->hasMember($invitee));
    }

    public function test_a_stale_token_does_not_break_login(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($user)->create();
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        $this->withSession(['pending_invitation_token' => 'does-not-exist'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', $workspace))
            ->assertSessionHas('error');
    }
}
