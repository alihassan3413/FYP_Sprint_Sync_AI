<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Mail\MemberInvitationMail;
use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\People\Models\Department;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\Modules\Workspace\Models\WorkspaceInviteLink;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * People is one list: profiles, SprintSync access and invitations merged so a
 * human appears once, with access given and changed from the same place.
 */
final class PeopleDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Ali Hassan', 'email' => 'ali@sprintsync.test']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
    }

    private function member(UserRole $role = UserRole::MEMBER, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    private function person(array $attributes = []): Person
    {
        return Person::factory()->for($this->workspace)->create($attributes);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function directory(?User $viewer = null): array
    {
        $rows = [];

        $this->actingAs($viewer ?? $this->owner)
            ->get(route('workspace.people.index', $this->workspace))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = $page->toArray()['props']['members'];
            });

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $name): array
    {
        $matches = array_values(array_filter($this->directory(), fn (array $row) => $row['name'] === $name));
        $this->assertCount(1, $matches, "Expected exactly one row for {$name}.");

        return $matches[0];
    }

    private function giveAccess(Person $person, array $data = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->owner)->post(
            route('workspace.people.access.store', [$this->workspace, $person]),
            ['email' => $person->email, 'role' => 'member', ...$data],
        );
    }

    public function test_a_linked_member_appears_once_with_job_details_and_access_role(): void
    {
        $department = Department::factory()->for($this->workspace)->create(['name' => 'Engineering']);
        $ali = $this->person(['name' => 'Ali Hassan', 'title' => 'Developer', 'email' => 'ali@sprintsync.test']);
        $ali->department()->associate($department);
        $ali->forceFill(['user_id' => $this->owner->id])->save();

        $row = $this->rowFor('Ali Hassan');

        $this->assertSame($this->owner->id, $row['id']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('owner', $row['role']);
        $this->assertSame(['public_id' => $ali->public_id, 'title' => 'Developer', 'department' => 'Engineering'], $row['person']);
        $this->assertFalse($row['link_suggested']);
        $this->assertCount(1, $this->directory());
    }

    public function test_people_without_a_login_and_members_without_a_profile_each_get_one_row(): void
    {
        $this->person(['name' => 'Aamir Sattar', 'title' => 'UI/UX Designer']);
        $usman = $this->member(attributes: ['name' => 'Muhammad Usman Ghani']);

        $aamir = $this->rowFor('Aamir Sattar');
        $this->assertSame('none', $aamir['status']);
        $this->assertNull($aamir['role']);
        $this->assertSame('UI/UX Designer', $aamir['person']['title']);

        $member = $this->rowFor('Muhammad Usman Ghani');
        $this->assertSame($usman->id, $member['id']);
        $this->assertNull($member['person']);
    }

    public function test_a_pending_invitation_merges_into_the_person_with_that_email(): void
    {
        $this->person(['name' => 'Aamir Sattar', 'email' => 'Aamir@Example.com']);
        $invitation = WorkspaceInvitation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'invited_by' => $this->owner->id,
            'email' => 'aamir@example.com',
        ]);

        $row = $this->rowFor('Aamir Sattar');

        $this->assertSame('pending', $row['status']);
        $this->assertSame($invitation->id, $row['invitation_id']);
        $this->assertCount(2, $this->directory());
    }

    public function test_a_member_with_a_matching_email_is_merged_and_offered_for_linking(): void
    {
        $this->person(['name' => 'Usman Ghani', 'email' => 'usman@example.com']);
        $this->member(attributes: ['name' => 'M. Usman', 'email' => 'usman@example.com']);

        $row = $this->rowFor('Usman Ghani');

        $this->assertSame('active', $row['status']);
        $this->assertTrue($row['link_suggested']);
        $this->assertCount(2, $this->directory());
    }

    public function test_guest_clients_are_never_merged_into_staff_profiles(): void
    {
        $this->person(['name' => 'Staff Copy', 'email' => 'guest@client.com']);
        $this->member(UserRole::CLIENT, ['name' => 'Client Guest', 'email' => 'guest@client.com']);

        $this->assertSame('none', $this->rowFor('Staff Copy')['status']);
        $this->assertSame('client', $this->rowFor('Client Guest')['role']);
    }

    public function test_giving_access_invites_the_person_and_shows_the_invitation_on_their_row(): void
    {
        Mail::fake();
        $role = WorkspaceRole::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Support Lead']);
        $aamir = $this->person(['name' => 'Aamir Sattar', 'email' => null]);

        $this->giveAccess($aamir, ['email' => ' Aamir@Example.com ', 'workspace_role_id' => $role->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Invitation sent to aamir@example.com.');

        $invitation = WorkspaceInvitation::query()->sole();
        $this->assertSame('aamir@example.com', $invitation->email);
        $this->assertSame(UserRole::MEMBER, $invitation->role);
        $this->assertSame($role->id, $invitation->workspace_role_id);
        $this->assertSame('aamir@example.com', $aamir->fresh()->email);
        Mail::assertQueued(MemberInvitationMail::class, fn ($mail) => $mail->hasTo('aamir@example.com'));

        $this->assertSame('pending', $this->rowFor('Aamir Sattar')['status']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::MEMBER_INVITED->value)->count());
    }

    public function test_accepting_the_invitation_links_the_person_automatically(): void
    {
        Mail::fake();
        $aamir = $this->person(['name' => 'Aamir Sattar', 'email' => 'aamir@example.com']);
        $this->giveAccess($aamir)->assertSessionHasNoErrors();
        $invitation = WorkspaceInvitation::query()->sole();

        auth()->logout();
        $this->post(route('workspace.invitations.accept.store', $invitation->token), [
            'name' => 'Aamir',
            'password' => 'Password!2345',
            'password_confirmation' => 'Password!2345',
        ])->assertRedirect(route('dashboard', $this->workspace));

        $user = User::query()->where('email', 'aamir@example.com')->sole();
        $this->assertSame($user->id, $aamir->fresh()->user_id);
        $this->assertSame('active', $this->rowFor('Aamir Sattar')['status']);
        $this->assertCount(2, $this->directory());

        $entry = AuditLog::query()->where('action', AuditAction::PERSON_USER_LINKED->value)->sole();
        $this->assertSame('Aamir joined SprintSync and was connected to Aamir Sattar.', $entry->description);
    }

    public function test_joining_by_invite_link_links_a_single_matching_person(): void
    {
        $aamir = $this->person(['email' => 'aamir@example.com']);
        $link = WorkspaceInviteLink::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);
        $joiner = User::factory()->create(['email' => 'AAMIR@example.com']);

        $this->actingAs($joiner)->post(route('workspace.join.store', $link->token))->assertRedirect();

        $this->assertSame($joiner->id, $aamir->fresh()->user_id);
    }

    public function test_an_ambiguous_email_links_nobody(): void
    {
        $first = $this->person(['email' => 'shared@example.com']);
        $second = $this->person(['email' => 'shared@example.com']);
        $link = WorkspaceInviteLink::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);

        $this->actingAs(User::factory()->create(['email' => 'shared@example.com']))
            ->post(route('workspace.join.store', $link->token))
            ->assertRedirect();

        $this->assertNull($first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);
    }

    public function test_giving_access_to_an_existing_member_links_instead_of_inviting(): void
    {
        Mail::fake();
        $usman = $this->member(attributes: ['email' => 'usman@example.com']);
        $person = $this->person(['name' => 'Muhammad Usman Ghani', 'email' => 'usman@example.com']);

        $this->giveAccess($person)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Muhammad Usman Ghani already had SprintSync access, so their profile is now linked.');

        $this->assertSame($usman->id, $person->fresh()->user_id);
        $this->assertSame(0, WorkspaceInvitation::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_access_cannot_be_given_twice_or_to_a_login_another_person_has(): void
    {
        $usman = $this->member(attributes: ['email' => 'usman@example.com']);
        $linked = $this->person(['email' => 'usman@example.com']);
        $linked->forceFill(['user_id' => $usman->id])->save();

        $this->giveAccess($linked)->assertSessionHasErrors('email');

        $other = $this->person(['name' => 'Someone Else']);
        $this->giveAccess($other, ['email' => 'usman@example.com'])->assertSessionHasErrors('email');

        $this->assertNull($other->fresh()->user_id);
        $this->assertSame(0, WorkspaceInvitation::query()->count());
    }

    public function test_give_access_validates_role_and_custom_role_scope(): void
    {
        $person = $this->person(['email' => 'aamir@example.com']);
        $foreignRole = WorkspaceRole::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

        $this->giveAccess($person, ['role' => 'owner'])->assertSessionHasErrors('role');
        $this->giveAccess($person, ['workspace_role_id' => $foreignRole->id])->assertSessionHasErrors('workspace_role_id');
        $this->giveAccess($person, ['email' => 'not-an-email'])->assertSessionHasErrors('email');

        $this->assertSame(0, WorkspaceInvitation::query()->count());
    }

    public function test_an_admin_can_still_invite_but_cannot_give_access_from_a_profile(): void
    {
        Mail::fake();
        $admin = $this->member(UserRole::ADMIN);
        $person = $this->person(['email' => 'aamir@example.com']);

        $this->giveAccess($person, actor: $admin)->assertForbidden();

        $this->actingAs($admin)
            ->post(route('workspace.invitations.store', $this->workspace), ['email' => 'dev@example.com', 'role' => 'member'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['dev@example.com'], WorkspaceInvitation::query()->pluck('email')->all());
    }

    public function test_removing_access_keeps_the_profile_as_no_access(): void
    {
        $usman = $this->member(attributes: ['email' => 'usman@example.com']);
        $person = $this->person(['name' => 'Muhammad Usman Ghani', 'email' => 'usman@example.com']);
        $person->forceFill(['user_id' => $usman->id])->save();

        $this->actingAs($this->owner)
            ->delete(route('workspace.members.destroy', [$this->workspace, $usman]))
            ->assertSessionHasNoErrors();

        $this->assertModelExists($person);
        $this->assertSame('none', $this->rowFor('Muhammad Usman Ghani')['status']);
    }

    public function test_changing_the_access_role_does_not_touch_the_job_title(): void
    {
        $usman = $this->member(attributes: ['email' => 'usman@example.com']);
        $person = $this->person(['name' => 'Muhammad Usman Ghani', 'title' => 'CSR']);
        $person->forceFill(['user_id' => $usman->id])->save();

        $this->actingAs($this->owner)
            ->patch(route('workspace.members.update', [$this->workspace, $usman]), ['role' => 'admin'])
            ->assertSessionHasNoErrors();

        $row = $this->rowFor('Muhammad Usman Ghani');
        $this->assertSame('admin', $row['role']);
        $this->assertSame('CSR', $row['person']['title']);
    }

    public function test_the_profile_page_shows_the_persons_access(): void
    {
        $person = $this->person(['name' => 'Ali Hassan']);
        $person->forceFill(['user_id' => $this->owner->id])->save();

        $this->actingAs($this->owner)
            ->get(route('workspace.people.show', [$this->workspace, $person]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('access.status', 'active')
                ->where('access.role', 'owner')
                ->where('access.person.public_id', $person->public_id)
                ->where('canInviteMembers', true)
                ->where('canManageMembers', true));
    }
}
