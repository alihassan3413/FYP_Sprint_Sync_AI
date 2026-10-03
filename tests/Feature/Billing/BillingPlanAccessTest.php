<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Recurring invoices are Finance: owner-only in v1, 404 across workspaces.
 */
final class BillingPlanAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Client $rocketFlood;

    private BillingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->rocketFlood = Client::factory()->rocketFlood()->for($this->workspace)->create();
        $this->plan = BillingPlan::factory()->for($this->rocketFlood)->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Dev & Office',
        ]);
        $this->plan->lines()->create(['position' => 0, 'description' => 'Office Rent Sentinel', 'unit_price_minor' => 110000]);
    }

    private function memberWithRole(UserRole $role, bool $withFinanceGrants = false): User
    {
        $user = User::factory()->create();
        $pivot = ['role' => $role->value];

        if ($withFinanceGrants) {
            $pivot['workspace_role_id'] = WorkspaceRole::factory()->create([
                'workspace_id' => $this->workspace->id,
                'permissions' => array_fill_keys(FinancePermission::values(), true),
            ])->id;
        }

        $this->workspace->users()->attach($user->id, $pivot);

        return $user;
    }

    /**
     * @return array<string, array{0: UserRole, 1: bool}>
     */
    public static function nonOwners(): array
    {
        return [
            'admin' => [UserRole::ADMIN, false],
            'member' => [UserRole::MEMBER, false],
            'guest client' => [UserRole::CLIENT, false],
            'admin with finance grants' => [UserRole::ADMIN, true],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function endpoints(): array
    {
        return [
            'create' => ['post', 'workspace.clients.plans.store', false],
            'update' => ['put', 'workspace.clients.plans.update', true],
            'pause' => ['post', 'workspace.clients.plans.pause', true],
            'resume' => ['post', 'workspace.clients.plans.resume', true],
        ];
    }

    private function hit(User $user, string $method, string $route, bool $needsPlan, ?Workspace $workspace = null): TestResponse
    {
        $parameters = ['workspace' => ($workspace ?? $this->workspace)->slug, 'client' => $this->rocketFlood->public_id];

        if ($needsPlan) {
            $parameters['billingPlan'] = $this->plan->public_id;
        }

        $payload = [
            'name' => 'Hijacked',
            'currency' => 'USD',
            'pricing_mode' => 'fixed',
            'send_day' => 1,
            'billing_period' => 'previous_month',
            'due_in_days' => 15,
            'delivery_mode' => 'auto_send',
            'lines' => [['description' => 'Hijack', 'unit_price' => '1']],
        ];

        return $this->actingAs($user)->{$method}(route($route, $parameters), $payload);
    }

    private function assertUntouched(): void
    {
        $this->assertSame(1, BillingPlan::query()->count());
        $this->assertSame('Dev & Office', $this->plan->fresh()->name);
        $this->assertFalse($this->plan->fresh()->isPaused());
    }

    #[DataProvider('nonOwners')]
    public function test_non_owners_cannot_touch_recurring_invoices(UserRole $role, bool $withFinanceGrants): void
    {
        $user = $this->memberWithRole($role, $withFinanceGrants);

        foreach (self::endpoints() as [$method, $route, $needsPlan]) {
            $this->hit($user, $method, $route, $needsPlan)->assertForbidden();
        }

        $this->actingAs($user)->get(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))->assertForbidden();

        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_outsiders_get_not_found(string $method, string $route, bool $needsPlan): void
    {
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->hit($outsider, $method, $route, $needsPlan)->assertNotFound();

        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_another_owner_cannot_reach_this_plan_through_their_own_workspace(string $method, string $route, bool $needsPlan): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $this->hit($otherOwner, $method, $route, $needsPlan, $otherWorkspace)->assertNotFound();

        $this->assertUntouched();
    }

    public function test_plan_data_never_leaks_into_other_pages(): void
    {
        $admin = $this->memberWithRole(UserRole::ADMIN);

        foreach ([$this->owner, $admin] as $user) {
            foreach (['dashboard', 'workspace.projects.index', 'workspace.settings', 'workspace.people.index', 'workspace.clients.index'] as $route) {
                $response = $this->actingAs($user)->get(route($route, $this->workspace));

                if ($response->isForbidden()) {
                    continue;
                }

                $content = $response->assertOk()->getContent();
                $this->assertStringNotContainsString('Office Rent Sentinel', $content, "{$route} leaked a plan line");
                $this->assertStringNotContainsString($this->plan->public_id, $content, "{$route} leaked a plan id");
            }
        }
    }

    public function test_recurring_invoice_audit_entries_are_billing_entries(): void
    {
        $this->assertSame('Billing', AuditAction::BILLING_PLAN_CREATED->category());
        $this->assertSame('Billing', AuditAction::BILLING_PLAN_PAUSED->category());
    }
}
