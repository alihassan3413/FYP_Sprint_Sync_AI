<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\Modules\Workspace\Models\WorkspaceInviteLink;
use App\UserRole;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RegistrationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ali Hassan',
            'email' => 'ali@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    public function test_registration_stores_the_detected_timezone(): void
    {
        $this->post(route('register'), $this->payload(['timezone' => 'Asia/Karachi']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Asia/Karachi', User::query()->firstOrFail()->timezone);
    }

    public function test_an_invalid_timezone_is_rejected_and_no_user_is_created(): void
    {
        $this->post(route('register'), $this->payload(['timezone' => 'Mars/Olympus_Mons']))
            ->assertSessionHasErrors('timezone');

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_missing_timezone_falls_back_to_the_application_timezone(): void
    {
        $this->post(route('register'), $this->payload())
            ->assertSessionHasNoErrors();

        $user = User::query()->firstOrFail();

        $this->assertNull($user->timezone);
        $this->assertSame(config('app.timezone'), $user->resolvedTimezone());
    }

    public function test_an_empty_timezone_is_treated_as_missing(): void
    {
        $this->post(route('register'), $this->payload(['timezone' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(User::query()->firstOrFail()->timezone);
    }

    public function test_registration_still_fires_the_registered_event(): void
    {
        Event::fake();

        $this->post(route('register'), $this->payload(['timezone' => 'Asia/Karachi']));

        Event::assertDispatched(Registered::class);
    }

    public function test_registration_still_sends_the_verification_email_and_leaves_the_user_unverified(): void
    {
        Notification::fake();

        $this->post(route('register'), $this->payload(['timezone' => 'Asia/Karachi']));

        $user = User::query()->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_still_creates_a_workspace_and_redirects_to_its_dashboard(): void
    {
        $this->post(route('register'), $this->payload([
            'timezone' => 'Asia/Karachi',
            'workspace_name' => 'Acme',
        ]));

        $user = User::query()->firstOrFail();
        $workspace = $user->activeWorkspaceOrFail();

        $this->assertSame('Acme', $workspace->name);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_invited_user_keeps_their_detected_timezone_and_stays_verified(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $invitation = WorkspaceInvitation::query()->create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => UserRole::MEMBER->value,
            'token' => Str::random(64),
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->post(route('workspace.invitations.accept.store', $invitation->token), [
            'name' => 'Invited Person',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'timezone' => 'America/New_York',
        ])->assertSessionHasNoErrors();

        $invitee = User::query()->where('email', 'invitee@example.com')->firstOrFail();

        $this->assertSame('America/New_York', $invitee->timezone);
        $this->assertTrue($invitee->hasVerifiedEmail());
    }

    public function test_an_invited_user_with_an_invalid_timezone_is_rejected(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $invitation = WorkspaceInvitation::query()->create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => UserRole::MEMBER->value,
            'token' => Str::random(64),
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->post(route('workspace.invitations.accept.store', $invitation->token), [
            'name' => 'Invited Person',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'timezone' => 'Nowhere/Nothing',
        ])->assertSessionHasErrors('timezone');

        $this->assertNull(User::query()->where('email', 'invitee@example.com')->first());
    }

    public function test_a_link_joiner_keeps_their_detected_timezone(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $link = WorkspaceInviteLink::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
        ]);

        $this->post(route('workspace.join.store', $link->token), [
            'name' => 'Link Joiner',
            'email' => 'joiner@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'timezone' => 'Australia/Sydney',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Australia/Sydney',
            User::query()->where('email', 'joiner@example.com')->firstOrFail()->timezone,
        );
    }

    public function test_a_link_joiner_without_a_timezone_falls_back(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();

        $link = WorkspaceInviteLink::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
        ]);

        $this->post(route('workspace.join.store', $link->token), [
            'name' => 'Link Joiner',
            'email' => 'joiner@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasNoErrors();

        $joiner = User::query()->where('email', 'joiner@example.com')->firstOrFail();

        $this->assertNull($joiner->timezone);
        $this->assertSame(config('app.timezone'), $joiner->resolvedTimezone());
    }

    public function test_verifying_email_does_not_overwrite_the_stored_timezone(): void
    {
        $this->post(route('register'), $this->payload(['timezone' => 'Asia/Karachi']));

        $user = User::query()->firstOrFail();

        $this->actingAs($user)->get(
            URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ]),
        );

        $user->refresh();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('Asia/Karachi', $user->timezone);
    }

    public function test_timezone_never_becomes_workspace_context(): void
    {
        $this->post(route('register'), $this->payload(['timezone' => 'Asia/Karachi']));

        $workspace = User::query()->firstOrFail()->activeWorkspaceOrFail();

        $this->assertArrayNotHasKey('timezone', $workspace->getAttributes());
    }
}
