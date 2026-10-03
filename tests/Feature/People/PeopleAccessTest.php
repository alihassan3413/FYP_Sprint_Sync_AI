<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\People\Models\Department;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * People sit behind the same owner-only Finance boundary as clients: admins,
 * members and guest clients are refused, other workspaces get a 404.
 */
final class PeopleAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Person $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->ali = Person::factory()->for($this->workspace)->create([
            'name' => 'Ali Hassan',
            'email' => 'ali.person@sprintsync.test',
            'title' => 'Staff Developer Sentinel',
        ]);
    }

    private function memberWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return [
            'admin' => [UserRole::ADMIN],
            'member' => [UserRole::MEMBER],
            'guest client' => [UserRole::CLIENT],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['get', 'workspace.people.index', false],
            'store' => ['post', 'workspace.people.store', false],
            'show' => ['get', 'workspace.people.show', true],
            'update' => ['put', 'workspace.people.update', true],
            'unlink' => ['delete', 'workspace.people.user.destroy', true],
            'give access' => ['post', 'workspace.people.access.store', true],
        ];
    }

    private function hit(User $user, string $method, string $route, bool $needsPerson, ?Workspace $workspace = null): TestResponse
    {
        $parameters = ['workspace' => ($workspace ?? $this->workspace)->slug];

        if ($needsPerson) {
            $parameters['person'] = $this->ali->public_id;
        }

        $payload = ['name' => 'Hijacked', 'department' => 'Hijackers', 'email' => 'hijacker@example.com', 'role' => 'admin'];

        return $this->actingAs($user)->{$method}(route($route, $parameters), $method === 'get' ? [] : $payload);
    }

    private function assertUntouched(): void
    {
        $this->assertSame('Ali Hassan', $this->ali->fresh()->name);
        $this->assertSame(1, Person::query()->count());
        $this->assertSame(0, Department::query()->count());
        $this->assertSame(0, WorkspaceInvitation::query()->count());
    }

    /**
     * The list itself is the old Team page, so it has its own visibility rules
     * (see the directory tests below); everything else is profile-only.
     */
    #[DataProvider('nonOwners')]
    public function test_non_owners_cannot_use_any_profile_endpoint(UserRole $role): void
    {
        $user = $this->memberWithRole($role);

        foreach (self::endpoints() as $name => [$method, $route, $needsPerson]) {
            if ($name === 'index') {
                continue;
            }

            $this->hit($user, $method, $route, $needsPerson)->assertForbidden();
        }

        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_someone_outside_the_workspace_gets_not_found(string $method, string $route, bool $needsPerson): void
    {
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->hit($outsider, $method, $route, $needsPerson)->assertNotFound();

        $this->assertUntouched();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function personEndpoints(): array
    {
        return array_filter(self::endpoints(), fn (array $endpoint) => $endpoint[2]);
    }

    #[DataProvider('personEndpoints')]
    public function test_an_owner_cannot_reach_another_workspaces_person_through_their_own_workspace(string $method, string $route): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $this->hit($otherOwner, $method, $route, true, $otherWorkspace)->assertNotFound();

        $this->assertUntouched();
    }

    public function test_the_list_only_contains_the_current_workspaces_people(): void
    {
        $other = Workspace::factory()->ownedBy($this->owner)->create();
        Person::factory()->for($other)->create(['name' => 'Elsewhere Person']);
        Department::factory()->for($other)->create(['name' => 'Elsewhere Dept']);

        $this->actingAs($this->owner)
            ->get(route('workspace.people.index', $this->workspace))
            ->assertInertia(fn ($page) => $page
                ->where('members', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Ali Hassan', $this->owner->name])
                ->has('departments', 0));
    }

    public function test_only_workspace_members_are_offered_for_linking(): void
    {
        $member = $this->memberWithRole(UserRole::MEMBER);
        $stranger = User::factory()->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.people.index', $this->workspace))
            ->assertInertia(fn ($page) => $page->where(
                'linkableUsers',
                fn ($users) => collect($users)->pluck('id')->sort()->values()->all() === collect([$this->owner->id, $member->id])->sort()->values()->all()
                    && ! collect($users)->contains('id', $stranger->id),
            ));
    }

    public function test_admins_and_members_see_the_roster_but_no_profiles(): void
    {
        foreach ([UserRole::ADMIN, UserRole::MEMBER] as $role) {
            $user = $this->memberWithRole($role);

            $this->actingAs($user)
                ->get(route('workspace.people.index', $this->workspace))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('people/index')
                    ->where('canViewProfiles', false)
                    ->where('canManagePeople', false)
                    ->where('members', fn ($rows) => collect($rows)->every(fn ($row) => ! array_key_exists('person', $row) && $row['status'] !== 'none'))
                    ->missing('departments')
                    ->missing('linkableUsers'))
                ->assertDontSee('ali.person@sprintsync.test')
                ->assertDontSee('Staff Developer Sentinel')
                ->assertDontSee($this->ali->public_id);
        }
    }

    public function test_guest_clients_cannot_open_people(): void
    {
        $this->actingAs($this->memberWithRole(UserRole::CLIENT))
            ->get(route('workspace.people.index', $this->workspace))
            ->assertForbidden();
    }

    public function test_the_people_card_in_settings_follows_team_visibility(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.settings', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('canViewTeam', true));

        $this->actingAs($this->memberWithRole(UserRole::MEMBER))
            ->get(route('workspace.settings', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('canViewTeam', true));
    }

    public function test_person_data_never_leaks_into_other_pages(): void
    {
        $admin = $this->memberWithRole(UserRole::ADMIN);

        foreach ([$this->owner, $admin] as $user) {
            foreach (['dashboard', 'workspace.projects.index', 'workspace.settings', 'workspace.clients.index', 'workspace.roles.index'] as $route) {
                $response = $this->actingAs($user)->get(route($route, $this->workspace));

                if ($response->isForbidden()) {
                    continue;
                }

                $content = $response->assertOk()->getContent();
                $this->assertStringNotContainsString('ali.person@sprintsync.test', $content, "{$route} leaked a person email");
                $this->assertStringNotContainsString($this->ali->public_id, $content, "{$route} leaked a person id");
            }
        }
    }

    public function test_people_audit_entries_are_hidden_from_admins_and_shown_to_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.people.store', $this->workspace), ['name' => 'Aamir Sattar', 'department' => 'Design'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, AuditLog::query()->where('workspace_id', $this->workspace->id)->count());

        $admin = $this->memberWithRole(UserRole::ADMIN);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 0)
                ->where('categories', fn ($categories) => ! collect($categories)->contains(AuditAction::PEOPLE_CATEGORY)));

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', ['workspace' => $this->workspace->slug, 'category' => AuditAction::PEOPLE_CATEGORY]))
            ->assertSessionHasErrors('category');

        $this->actingAs($this->owner)
            ->get(route('workspace.audit.index', ['workspace' => $this->workspace->slug, 'category' => AuditAction::PEOPLE_CATEGORY]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entries.total', 2));
    }
}
