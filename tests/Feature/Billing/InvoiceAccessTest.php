<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\TestCase;

/**
 * Invoices are Finance: owner-only in v1, 404 across workspaces, never by
 * numeric id, and never visible on unrelated pages.
 */
final class InvoiceAccessTest extends TestCase
{
    use BuildsRocketFlood, RefreshDatabase;

    private BillingPlan $plan;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->setUpRocketFlood();
        $this->plan = $this->devAndOffice();
        $this->invoice = app(GenerateInvoiceFromPlanAction::class)->handle(
            $this->plan,
            app(BillingSchedule::class)->latestDueCycle($this->plan, CarbonImmutable::parse('2026-10-03')),
            $this->owner,
        );
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
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'list' => ['get', 'workspace.invoices.index'],
            'view' => ['get', 'workspace.invoices.show'],
            'edit lines' => ['put', 'workspace.invoices.lines.update'],
            'approve' => ['post', 'workspace.invoices.approve'],
            'cancel sending' => ['post', 'workspace.invoices.cancel-sending'],
            'generate' => ['post', 'workspace.clients.plans.invoices.store'],
        ];
    }

    private function hit(User $user, string $method, string $route, ?Workspace $workspace = null): TestResponse
    {
        $parameters = match ($route) {
            'workspace.invoices.index' => [],
            'workspace.clients.plans.invoices.store' => ['client' => $this->rocketFlood->public_id, 'billingPlan' => $this->plan->public_id],
            default => ['invoice' => $this->invoice->public_id],
        };

        return $this->actingAs($user)->{$method}(
            route($route, ['workspace' => ($workspace ?? $this->workspace)->slug, ...$parameters]),
            $method === 'get' ? [] : ['lines' => ['5' => '1'], 'version' => $this->invoice->version],
        );
    }

    private function assertUntouched(): void
    {
        $invoice = $this->invoice->fresh();

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(505920, $invoice->total_minor);
        $this->assertNull($invoice->number);
    }

    #[DataProvider('nonOwners')]
    public function test_non_owners_are_refused_everywhere(UserRole $role, bool $withFinanceGrants): void
    {
        $user = $this->memberWithRole($role, $withFinanceGrants);

        foreach (self::endpoints() as [$method, $route]) {
            $this->hit($user, $method, $route)->assertForbidden();
        }

        $this->assertUntouched();
    }

    #[DataProvider('endpoints')]
    public function test_outsiders_get_not_found(string $method, string $route): void
    {
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->hit($outsider, $method, $route)->assertNotFound();

        $this->assertUntouched();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invoiceEndpoints(): array
    {
        return array_filter(self::endpoints(), fn (array $endpoint) => $endpoint[1] !== 'workspace.invoices.index');
    }

    #[DataProvider('invoiceEndpoints')]
    public function test_another_owner_cannot_reach_this_invoice_through_their_own_workspace(string $method, string $route): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $this->hit($otherOwner, $method, $route, $otherWorkspace)->assertNotFound();

        $this->assertUntouched();
    }

    public function test_another_workspaces_list_does_not_include_this_invoice(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $this->actingAs($otherOwner)
            ->get(route('workspace.invoices.index', $otherWorkspace))
            ->assertOk()
            ->assertDontSee($this->invoice->public_id);
    }

    public function test_numeric_ids_are_not_found(): void
    {
        $this->actingAs($this->owner)->get("/{$this->workspace->slug}/invoices/{$this->invoice->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/{$this->workspace->slug}/invoices/01ARZ3NDEKTSV4RRFFQ69G5FAV")->assertNotFound();
    }

    public function test_invoice_data_never_leaks_into_other_pages(): void
    {
        $admin = $this->memberWithRole(UserRole::ADMIN);

        foreach ([$this->owner, $admin] as $user) {
            foreach (['dashboard', 'workspace.projects.index', 'workspace.settings', 'workspace.people.index', 'workspace.clients.index'] as $route) {
                $response = $this->actingAs($user)->get(route($route, $this->workspace));

                if ($response->isForbidden()) {
                    continue;
                }

                $content = $response->assertOk()->getContent();
                $this->assertStringNotContainsString($this->invoice->public_id, $content, "{$route} leaked an invoice id");
                $this->assertStringNotContainsString('505920', $content, "{$route} leaked an invoice total");
            }
        }
    }

    public function test_only_the_owner_sees_invoices_in_the_navigation(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('navigation.finance', true));

        $this->actingAs($this->memberWithRole(UserRole::ADMIN))
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('navigation.finance', false));
    }
}
