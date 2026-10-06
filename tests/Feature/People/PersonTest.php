<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\People\Actions\SetPersonUserAction;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Department;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

final class PersonTest extends TestCase
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

    private function addPerson(array $data): Person
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.people.store', $this->workspace), $data)
            ->assertSessionHasNoErrors();

        return Person::query()->latest('id')->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function auditActions(): array
    {
        return AuditLog::query()->where('workspace_id', $this->workspace->id)->orderBy('id')->pluck('action')->all();
    }

    public function test_the_owner_adds_a_person_with_a_new_department_created_inline(): void
    {
        $response = $this->actingAs($this->owner)->post(route('workspace.people.store', $this->workspace), [
            'name' => '  Aamir Sattar ',
            'email' => 'aamir@example.com',
            'title' => 'UI/UX Designer',
            'department' => ' Design ',
            'user_id' => '',
        ]);

        $person = Person::query()->sole();

        $response->assertRedirect(route('workspace.people.show', [$this->workspace, $person, 'added' => 1]))
            ->assertSessionHas('success', 'Aamir Sattar added.');

        $this->assertSame($this->workspace->id, $person->workspace_id);
        $this->assertSame('Aamir Sattar', $person->name);
        $this->assertSame('UI/UX Designer', $person->title);
        $this->assertSame('Design', $person->department->name);
        $this->assertNull($person->user_id);
        $this->assertSame([AuditAction::DEPARTMENT_CREATED->value, AuditAction::PERSON_CREATED->value], $this->auditActions());
    }

    public function test_a_new_profile_with_a_members_email_is_connected_automatically(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.people.store', $this->workspace), ['name' => 'Ali Hassan', 'email' => 'ALI@sprintsync.test'])
            ->assertSessionHas('success', 'Ali Hassan added and connected to their SprintSync login.');

        $this->assertSame($this->owner->id, Person::query()->sole()->user_id);
        $this->assertSame([AuditAction::PERSON_CREATED->value, AuditAction::PERSON_USER_LINKED->value], $this->auditActions());
    }

    public function test_auto_connect_skips_guest_clients_and_logins_already_connected(): void
    {
        $guest = $this->member(UserRole::CLIENT, ['email' => 'guest@client.com']);
        $guestProfile = $this->addPerson(['name' => 'Guest Copy', 'email' => 'guest@client.com']);
        $this->assertNull($guestProfile->user_id);

        $this->addPerson(['name' => 'Ali Hassan', 'email' => 'ali@sprintsync.test']);
        $second = $this->addPerson(['name' => 'Ali Duplicate', 'email' => 'ali@sprintsync.test']);
        $this->assertNull($second->user_id);

        $this->actingAs($this->owner)
            ->get(route('workspace.people.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where(
                'linkableUsers',
                fn ($users) => ! collect($users)->contains('id', $guest->id),
            ));
    }

    public function test_a_person_needs_only_a_name(): void
    {
        $person = $this->addPerson(['name' => 'Aamir Sattar', 'email' => '', 'title' => '', 'department' => '']);

        $this->assertNull($person->email);
        $this->assertNull($person->title);
        $this->assertNull($person->department_id);
        $this->assertSame(0, Department::query()->count());
    }

    public function test_validation_rejects_a_missing_name_and_a_bad_email(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.people.store', $this->workspace), ['name' => ' ', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->assertSame(0, Person::query()->count());
    }

    public function test_departments_are_reused_case_insensitively(): void
    {
        $this->addPerson(['name' => 'Ali Hassan', 'department' => 'Engineering']);
        $this->addPerson(['name' => 'Another Dev', 'department' => '  engineering  ']);

        $this->assertSame(1, Department::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::DEPARTMENT_CREATED->value)->count());
        $this->assertSame(['Engineering'], Department::query()->pluck('name')->all());
    }

    public function test_the_database_rejects_a_duplicate_department(): void
    {
        Department::factory()->for($this->workspace)->create(['name' => 'Design']);

        $this->expectException(UniqueConstraintViolationException::class);

        Department::factory()->for($this->workspace)->create(['name' => 'DESIGN ']);
    }

    public function test_the_same_department_name_is_allowed_in_another_workspace(): void
    {
        Department::factory()->for(Workspace::factory()->create())->create(['name' => 'Design']);

        $this->addPerson(['name' => 'Aamir Sattar', 'department' => 'Design']);

        $this->assertSame(2, Department::query()->count());
        $this->assertSame($this->workspace->id, Person::query()->sole()->department->workspace_id);
    }

    public function test_the_owner_views_the_list_and_a_person(): void
    {
        $person = $this->addPerson(['name' => 'Muhammad Usman Ghani', 'title' => 'CSR', 'department' => 'Customer Support']);

        $this->actingAs($this->owner)->get(route('workspace.people.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('people/index')
                ->where('canManagePeople', true)
                ->has('members', 2)
                ->where('members.1.id', "person-{$person->public_id}")
                ->where('members.1.status', 'none')
                ->where('members.1.person', [
                    'public_id' => $person->public_id,
                    'title' => 'CSR',
                    'department' => 'Customer Support',
                ])
                ->where('departments.0.name', 'Customer Support'));

        $this->actingAs($this->owner)->get(route('workspace.people.show', [$this->workspace, $person]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('people/show')
                ->where('person.name', 'Muhammad Usman Ghani')
                ->where('person.title', 'CSR')
                ->where('access.status', 'none')
                ->missing('person.id')
                ->missing('person.workspace_id'));
    }

    public function test_the_owner_edits_a_person_and_the_change_is_audited(): void
    {
        $person = $this->addPerson(['name' => 'Aamir Sattar', 'title' => 'Designer', 'department' => 'Design']);

        $this->actingAs($this->owner)
            ->put(route('workspace.people.update', [$this->workspace, $person]), [
                'name' => 'Aamir Sattar',
                'title' => 'UI/UX Designer',
                'department' => 'Design',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Aamir Sattar updated.');

        $this->assertSame('UI/UX Designer', $person->fresh()->title);

        $entry = AuditLog::query()->where('action', AuditAction::PERSON_UPDATED->value)->sole();
        $this->assertSame(['title'], $entry->metadata['changed']);
    }

    public function test_saving_without_changes_writes_no_audit_entry(): void
    {
        $person = $this->addPerson(['name' => 'Aamir Sattar', 'title' => 'Designer']);

        $this->actingAs($this->owner)
            ->put(route('workspace.people.update', [$this->workspace, $person]), ['name' => 'Aamir Sattar', 'title' => 'Designer'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::PERSON_UPDATED->value)->count());
    }

    public function test_a_person_can_be_linked_to_a_workspace_member_and_unlinked(): void
    {
        $person = $this->addPerson(['name' => 'Ali Hassan', 'user_id' => $this->owner->id]);

        $this->assertSame($this->owner->id, $person->user_id);

        $this->actingAs($this->owner)->get(route('workspace.people.show', [$this->workspace, $person]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('person.linked_user.email', 'ali@sprintsync.test')
                ->where('linkableUsers.0.linked_person', $person->public_id));

        $this->actingAs($this->owner)
            ->delete(route('workspace.people.user.destroy', [$this->workspace, $person]))
            ->assertSessionHasNoErrors();

        $this->assertNull($person->fresh()->user_id);

        // A repeated unlink (double click, back button) is harmless.
        $this->actingAs($this->owner)
            ->delete(route('workspace.people.user.destroy', [$this->workspace, $person]))
            ->assertSessionHasNoErrors();

        $this->assertSame([
            AuditAction::PERSON_CREATED->value,
            AuditAction::PERSON_USER_LINKED->value,
            AuditAction::PERSON_USER_UNLINKED->value,
        ], $this->auditActions());
    }

    public function test_linking_through_edit_and_switching_users_is_audited(): void
    {
        $member = $this->member();
        $person = $this->addPerson(['name' => 'Aamir Sattar']);

        $this->actingAs($this->owner)
            ->put(route('workspace.people.update', [$this->workspace, $person]), ['name' => 'Aamir Sattar', 'user_id' => $this->owner->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)
            ->put(route('workspace.people.update', [$this->workspace, $person]), ['name' => 'Aamir Sattar', 'user_id' => $member->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($member->id, $person->fresh()->user_id);
        $this->assertSame([
            AuditAction::PERSON_CREATED->value,
            AuditAction::PERSON_USER_LINKED->value,
            AuditAction::PERSON_USER_UNLINKED->value,
            AuditAction::PERSON_USER_LINKED->value,
        ], $this->auditActions());
    }

    public function test_one_user_cannot_be_linked_to_two_people(): void
    {
        $this->addPerson(['name' => 'Ali Hassan', 'user_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->post(route('workspace.people.store', $this->workspace), ['name' => 'Ali Again', 'user_id' => $this->owner->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, Person::query()->count());

        $other = $this->addPerson(['name' => 'Aamir Sattar']);

        $this->actingAs($this->owner)
            ->put(route('workspace.people.update', [$this->workspace, $other]), ['name' => 'Aamir Sattar', 'user_id' => $this->owner->id])
            ->assertSessionHasErrors('user_id');

        $this->assertNull($other->fresh()->user_id);
    }

    public function test_the_action_and_database_block_a_double_link_that_slips_past_validation(): void
    {
        $first = Person::factory()->for($this->workspace)->create();
        $second = Person::factory()->for($this->workspace)->create();
        $linker = app(SetPersonUserAction::class);

        $linker->handle($first, $this->owner, $this->owner);

        try {
            $linker->handle($second, $this->owner, $this->owner);
            $this->fail('Expected the second link to be rejected.');
        } catch (PeopleException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertNull($second->fresh()->user_id);

        $this->expectException(UniqueConstraintViolationException::class);
        Person::query()->whereKey($second->id)->update(['user_id' => $this->owner->id]);
    }

    public function test_a_user_from_another_workspace_cannot_be_linked(): void
    {
        $stranger = User::factory()->create();
        Workspace::factory()->ownedBy($stranger)->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.people.store', $this->workspace), ['name' => 'Stranger', 'user_id' => $stranger->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, Person::query()->count());

        $person = Person::factory()->for($this->workspace)->create();

        $this->expectException(PeopleException::class);
        app(SetPersonUserAction::class)->handle($person, $stranger, $this->owner);
    }

    public function test_removing_a_member_from_the_workspace_unlinks_their_person(): void
    {
        $member = $this->member();
        $person = $this->addPerson(['name' => 'Muhammad Usman Ghani', 'user_id' => $member->id]);

        $this->actingAs($this->owner)
            ->delete(route('workspace.members.destroy', [$this->workspace, $member]))
            ->assertSessionHasNoErrors();

        $this->assertNull($person->fresh()->user_id);
        $this->assertModelExists($person);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PERSON_USER_UNLINKED->value)->count());
    }

    public function test_people_are_addressed_by_ulid_only(): void
    {
        $person = Person::factory()->for($this->workspace)->create();

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $person->public_id);
        $this->assertStringEndsWith('/team/'.$person->public_id, route('workspace.people.show', [$this->workspace, $person]));

        $this->actingAs($this->owner)->get("/{$this->workspace->slug}/team/{$person->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/{$this->workspace->slug}/team/01ARZ3NDEKTSV4RRFFQ69G5FAV")->assertNotFound();
    }

    public function test_the_public_id_cannot_change(): void
    {
        $person = Person::factory()->for($this->workspace)->create();

        $this->expectException(LogicException::class);
        $person->forceFill(['public_id' => strtolower((string) Str::ulid())])->save();
    }
}
