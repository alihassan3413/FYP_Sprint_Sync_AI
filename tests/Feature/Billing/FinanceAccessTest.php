<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Data\WorkspacePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Finance is owner-only in v1. Workspace admin rank, custom roles (even ones
 * carrying billing.* grants) and guest clients must never reach it.
 */
final class FinanceAccessTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Client $rocketFlood;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->rocketFlood = Client::factory()->rocketFlood()->for($this->workspace)->create();
    }

    private function memberWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    /**
     * A member whose custom role carries every finance grant, e.g. left over
     * from the old "Billing" toggles. v1 must not honour them.
     */
    private function memberWithFinanceGrants(UserRole $baseRole = UserRole::MEMBER): User
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'permissions' => array_fill_keys(FinancePermission::values(), true),
        ]);

        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $baseRole->value, 'workspace_role_id' => $role->id]);

        return $user;
    }

    private function userFor(string $who): User
    {
        return match ($who) {
            'admin' => $this->memberWithRole(UserRole::ADMIN),
            'member' => $this->memberWithRole(UserRole::MEMBER),
            'guest client' => $this->memberWithRole(UserRole::CLIENT),
            'member with finance grants' => $this->memberWithFinanceGrants(),
            'admin with finance grants' => $this->memberWithFinanceGrants(UserRole::ADMIN),
        };
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonOwners(): array
    {
        return [
            'admin' => ['admin'],
            'member' => ['member'],
            'guest client' => ['guest client'],
            'member with finance grants' => ['member with finance grants'],
            'admin with finance grants' => ['admin with finance grants'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function clientEndpoints(): array
    {
        return [
            'index' => ['get', 'workspace.clients.index', false],
            'store' => ['post', 'workspace.clients.store', false],
            'show' => ['get', 'workspace.clients.show', true],
            'update' => ['put', 'workspace.clients.update', true],
            'archive' => ['post', 'workspace.clients.archive', true],
            'restore' => ['post', 'workspace.clients.restore', true],
        ];
    }

    private function hit(User $user, string $method, string $route, bool $needsClient, ?Workspace $workspace = null, ?Client $client = null): TestResponse
    {
        $parameters = ['workspace' => ($workspace ?? $this->workspace)->slug];

        if ($needsClient) {
            $parameters['client'] = ($client ?? $this->rocketFlood)->public_id;
        }

        $payload = ['name' => 'Hijacked', 'billing_email' => 'attacker@example.com', 'currency' => 'USD'];

        return $this->actingAs($user)->{$method}(route($route, $parameters), $method === 'get' ? [] : $payload);
    }

    public function test_the_owner_has_finance_access(): void
    {
        foreach (FinancePermission::cases() as $permission) {
            $this->assertTrue($this->workspace->allowsFinance($this->owner, $permission));
        }

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.index', $this->workspace))
            ->assertOk();
    }

    #[DataProvider('nonOwners')]
    public function test_non_owners_have_no_finance_permission(string $who): void
    {
        $user = $this->userFor($who);

        foreach (FinancePermission::cases() as $permission) {
            $this->assertFalse($this->workspace->allowsFinance($user, $permission), "{$who} should not hold {$permission->value}");
        }
    }

    #[DataProvider('nonOwners')]
    public function test_non_owners_cannot_use_any_client_endpoint(string $who): void
    {
        $user = $this->userFor($who);

        foreach (self::clientEndpoints() as $name => [$method, $route, $needsClient]) {
            $this->hit($user, $method, $route, $needsClient)->assertForbidden();
        }

        $this->rocketFlood->refresh();
        $this->assertSame('RocketFlood', $this->rocketFlood->name);
        $this->assertNull($this->rocketFlood->archived_at);
        $this->assertDatabaseCount('clients', 1);
    }

    #[DataProvider('clientEndpoints')]
    public function test_someone_outside_the_workspace_gets_not_found(string $method, string $route, bool $needsClient): void
    {
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->hit($outsider, $method, $route, $needsClient)->assertNotFound();

        $this->assertSame('RocketFlood', $this->rocketFlood->fresh()->name);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function clientScopedEndpoints(): array
    {
        return array_filter(self::clientEndpoints(), fn (array $endpoint) => $endpoint[2]);
    }

    #[DataProvider('clientScopedEndpoints')]
    public function test_an_owner_cannot_reach_another_workspaces_client_through_their_own_workspace(string $method, string $route): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $this->hit($otherOwner, $method, $route, true, $otherWorkspace, $this->rocketFlood)->assertNotFound();

        $this->rocketFlood->refresh();
        $this->assertSame('RocketFlood', $this->rocketFlood->name);
        $this->assertNull($this->rocketFlood->archived_at);
    }

    public function test_the_client_list_only_contains_the_current_workspaces_clients(): void
    {
        $other = Workspace::factory()->ownedBy($this->owner)->create();
        Client::factory()->for($other)->create(['name' => 'Elsewhere Ltd']);

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.index', $this->workspace))
            ->assertInertia(fn ($page) => $page->has('clients', 1)->where('clients.0.name', 'RocketFlood'));
    }

    public function test_only_the_owner_sees_the_money_navigation(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('navigation.finance', true));

        foreach (array_keys(self::nonOwners()) as $who) {
            $this->actingAs($this->userFor($who))
                ->get(route('dashboard', $this->workspace))
                ->assertInertia(fn ($page) => $page->where('navigation.finance', false));
        }
    }

    public function test_admins_no_longer_receive_billing_permissions_in_shared_capabilities(): void
    {
        $admin = $this->memberWithRole(UserRole::ADMIN);

        $granted = $this->workspace->grantedPermissionsFor($admin);

        $this->assertSame(WorkspacePermission::values(), $granted);
        $this->assertSame([], array_intersect(FinancePermission::values(), $granted));
    }

    public function test_client_data_never_leaks_into_shared_props_of_other_pages(): void
    {
        $this->rocketFlood->update(['cc_emails' => ['finance@rocketflood.com'], 'tax_id' => 'TAX-123']);
        $admin = $this->memberWithRole(UserRole::ADMIN);

        foreach ([$this->owner, $admin] as $user) {
            foreach (['dashboard', 'workspace.projects.index', 'workspace.settings', 'workspace.teams.index'] as $route) {
                $content = $this->actingAs($user)->get(route($route, $this->workspace))->assertOk()->getContent();

                $this->assertStringNotContainsString('billing@rocketflood.com', $content, "{$route} leaked a client email");
                $this->assertStringNotContainsString('TAX-123', $content, "{$route} leaked a client tax ID");
            }
        }
    }

    public function test_the_role_editor_no_longer_offers_billing_toggles(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.roles.index', $this->workspace))
            ->assertInertia(fn ($page) => $page->where(
                'availablePermissions',
                fn ($permissions) => array_intersect(FinancePermission::values(), collect($permissions)->all()) === [],
            ));
    }

    public function test_billing_audit_entries_are_hidden_from_admins_and_shown_to_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->put(route('workspace.clients.update', [$this->workspace, $this->rocketFlood]), [
                'name' => 'RocketFlood',
                'billing_email' => 'accounts@rocketflood.com',
                'currency' => 'USD',
            ]);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CLIENT_UPDATED->value)->count());

        $admin = $this->memberWithRole(UserRole::ADMIN);

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 0)
                ->where('categories', fn ($categories) => ! collect($categories)->contains(AuditAction::BILLING_CATEGORY)));

        $this->actingAs($admin)
            ->get(route('workspace.audit.index', ['workspace' => $this->workspace->slug, 'category' => AuditAction::BILLING_CATEGORY]))
            ->assertSessionHasErrors('category');

        $this->actingAs($this->owner)
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 1)
                ->where('entries.data.0.category', AuditAction::BILLING_CATEGORY)
                ->where('categories', fn ($categories) => collect($categories)->contains(AuditAction::BILLING_CATEGORY)));
    }
}
