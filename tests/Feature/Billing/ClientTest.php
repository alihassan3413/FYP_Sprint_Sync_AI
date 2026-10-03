<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Ali Hassan']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rocketFlood(array $overrides = []): array
    {
        return [
            'name' => 'RocketFlood',
            'billing_email' => 'billing@rocketflood.com',
            'currency' => 'USD',
            ...$overrides,
        ];
    }

    public function test_the_owner_can_add_rocketflood(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood());

        $client = Client::query()->sole();

        $response->assertRedirect(route('workspace.clients.show', [$this->workspace, $client]))
            ->assertSessionHas('success', 'RocketFlood added.');

        $this->assertSame($this->workspace->id, $client->workspace_id);
        $this->assertSame('RocketFlood', $client->name);
        $this->assertSame('billing@rocketflood.com', $client->billing_email);
        $this->assertSame(Currency::USD, $client->currency);
        $this->assertNull($client->archived_at);
    }

    public function test_optional_details_are_saved_and_cc_emails_accept_a_comma_separated_string(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood([
                'cc_emails' => 'finance@rocketflood.com, ceo@rocketflood.com',
                'address' => "1200 Market Street\nSan Francisco, CA",
                'tax_id' => '12-3456789',
            ]))
            ->assertSessionHasNoErrors();

        $client = Client::query()->sole();

        $this->assertSame(['finance@rocketflood.com', 'ceo@rocketflood.com'], $client->cc_emails);
        $this->assertSame("1200 Market Street\nSan Francisco, CA", $client->address);
        $this->assertSame('12-3456789', $client->tax_id);
    }

    public function test_blank_optional_fields_are_stored_as_null(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood(['cc_emails' => '', 'address' => '  ', 'tax_id' => '']))
            ->assertSessionHasNoErrors();

        $client = Client::query()->sole();

        $this->assertNull($client->cc_emails);
        $this->assertNull($client->address);
        $this->assertNull($client->tax_id);
    }

    public function test_creating_a_client_is_audited_in_the_billing_category(): void
    {
        $this->actingAs($this->owner)->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood());

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'action' => AuditAction::CLIENT_CREATED->value,
            'subject_type' => Client::class,
            'description' => 'Ali Hassan added the client "RocketFlood".',
        ]);
        $this->assertSame('Billing', AuditAction::CLIENT_CREATED->category());
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), [])
            ->assertSessionHasErrors(['name', 'billing_email', 'currency']);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_the_billing_email_must_be_valid(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood(['billing_email' => 'not-an-email']))
            ->assertSessionHasErrors('billing_email');
    }

    public function test_an_unsupported_currency_is_rejected(): void
    {
        foreach (['XYZ', 'usd', 'US Dollar', ''] as $currency) {
            $this->actingAs($this->owner)
                ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood(['currency' => $currency]))
                ->assertSessionHasErrors('currency');
        }

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_invalid_or_too_many_cc_emails_are_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood(['cc_emails' => 'finance@rocketflood.com, nope']))
            ->assertSessionHasErrors('cc_emails.1');

        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood([
                'cc_emails' => implode(',', array_map(fn (int $i) => "person{$i}@rocketflood.com", range(1, 6))),
            ]))
            ->assertSessionHasErrors('cc_emails');

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_client_names_are_unique_within_a_workspace_but_not_across_workspaces(): void
    {
        Client::factory()->rocketFlood()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood())
            ->assertSessionHasErrors('name');

        $otherWorkspace = Workspace::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $otherWorkspace), $this->rocketFlood())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('clients', 2);
    }

    public function test_workspace_and_archive_state_cannot_be_mass_assigned(): void
    {
        $otherWorkspace = Workspace::factory()->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.clients.store', $this->workspace), $this->rocketFlood([
                'workspace_id' => $otherWorkspace->id,
                'archived_at' => now()->toDateTimeString(),
                'id' => 999,
                'public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'logo_path' => '../../.env',
            ]))
            ->assertSessionHasNoErrors();

        $client = Client::query()->sole();

        $this->assertSame($this->workspace->id, $client->workspace_id);
        $this->assertNull($client->archived_at);
        $this->assertNotSame(999, $client->id);
        $this->assertNotSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $client->public_id);
        $this->assertNull($client->logo_path);
    }

    public function test_the_index_lists_clients_alphabetically_with_currency_options(): void
    {
        Client::factory()->for($this->workspace)->create(['name' => 'Zeta Labs']);
        Client::factory()->rocketFlood()->for($this->workspace)->create();
        Client::factory()->for($this->workspace)->archived()->create(['name' => 'Acme Old']);

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('clients/index')
                ->has('clients', 3)
                ->where('clients.0.name', 'Acme Old')
                ->whereNot('clients.0.archived_at', null)
                ->where('clients.1.name', 'RocketFlood')
                ->where('clients.1.currency', 'USD')
                ->where('canManageClients', true)
                ->where('currencies.0', ['value' => 'USD', 'label' => 'USD — US dollar']));
    }

    public function test_the_client_page_shows_rocketflood(): void
    {
        $client = Client::factory()->rocketFlood()->for($this->workspace)->create(['cc_emails' => ['finance@rocketflood.com']]);

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $client]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('clients/show')
                ->where('client.public_id', $client->public_id)
                ->where('client.name', 'RocketFlood')
                ->where('client.billing_email', 'billing@rocketflood.com')
                ->where('client.currency', 'USD')
                ->where('client.cc_emails', ['finance@rocketflood.com'])
                ->where('canManageClients', true));
    }

    public function test_the_owner_can_update_a_client_and_the_change_is_audited(): void
    {
        $client = Client::factory()->rocketFlood()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->put(route('workspace.clients.update', [$this->workspace, $client]), $this->rocketFlood([
                'billing_email' => 'accounts@rocketflood.com',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('accounts@rocketflood.com', $client->fresh()->billing_email);

        $entry = AuditLog::query()->where('workspace_id', $this->workspace->id)->where('action', AuditAction::CLIENT_UPDATED->value)->sole();
        $this->assertSame(['changed' => ['billing_email']], $entry->metadata);
    }

    public function test_saving_without_changes_writes_no_audit_entry(): void
    {
        $client = Client::factory()->rocketFlood()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->put(route('workspace.clients.update', [$this->workspace, $client]), $this->rocketFlood())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::CLIENT_UPDATED->value]);
    }

    public function test_a_client_keeps_its_own_name_when_updated(): void
    {
        $client = Client::factory()->rocketFlood()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->put(route('workspace.clients.update', [$this->workspace, $client]), $this->rocketFlood(['currency' => 'PKR']))
            ->assertSessionHasNoErrors();

        $this->assertSame(Currency::PKR, $client->fresh()->currency);
    }

    public function test_the_owner_can_archive_and_restore_a_client(): void
    {
        $client = Client::factory()->rocketFlood()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->post(route('workspace.clients.archive', [$this->workspace, $client]))
            ->assertRedirect(route('workspace.clients.index', $this->workspace));

        $this->assertNotNull($client->fresh()->archived_at);
        $this->assertModelExists($client);

        $this->actingAs($this->owner)
            ->post(route('workspace.clients.restore', [$this->workspace, $client]))
            ->assertRedirect();

        $this->assertNull($client->fresh()->archived_at);
        $this->assertSame(
            [AuditAction::CLIENT_ARCHIVED->value, AuditAction::CLIENT_RESTORED->value],
            AuditLog::query()->where('workspace_id', $this->workspace->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_archiving_twice_is_harmless_and_audited_once(): void
    {
        $client = Client::factory()->rocketFlood()->for($this->workspace)->create();

        $this->actingAs($this->owner)->post(route('workspace.clients.archive', [$this->workspace, $client]));
        $this->actingAs($this->owner)->post(route('workspace.clients.archive', [$this->workspace, $client]));

        $this->assertSame(1, AuditLog::query()->where('workspace_id', $this->workspace->id)->where('action', AuditAction::CLIENT_ARCHIVED->value)->count());
    }

    public function test_clients_are_deleted_with_their_workspace(): void
    {
        $client = Client::factory()->for($this->workspace)->create();

        $this->workspace->delete();

        $this->assertModelMissing($client);
    }
}
