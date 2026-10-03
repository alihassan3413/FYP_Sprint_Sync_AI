<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Clients are addressed by an opaque ULID. This is privacy, not security:
 * tenancy and ClientPolicy still decide access (see FinanceAccessTest).
 */
final class ClientPublicIdTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
    }

    public function test_every_client_gets_its_own_ulid(): void
    {
        $first = Client::factory()->for($this->workspace)->create();
        $second = Client::factory()->for($this->workspace)->create();

        $this->assertTrue(Str::isUlid($first->public_id));
        $this->assertTrue(Str::isUlid($second->public_id));
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertSame('public_id', $first->getRouteKeyName());
        $this->assertSame($first->public_id, $first->getRouteKey());
        $this->assertIsInt($first->getKey());
    }

    public function test_creating_a_client_redirects_to_its_public_id_url(): void
    {
        $response = $this->actingAs($this->owner)->post(route('workspace.clients.store', $this->workspace), [
            'name' => 'RocketFlood',
            'billing_email' => 'billing@rocketflood.test',
            'currency' => 'USD',
        ]);

        $client = Client::query()->sole();

        $response->assertRedirect("/{$this->workspace->slug}/clients/{$client->public_id}");
        $this->assertStringNotContainsString("/clients/{$client->id}", (string) $response->headers->get('Location'));
    }

    public function test_the_numeric_database_id_does_not_open_a_client(): void
    {
        $client = Client::factory()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->get("/{$this->workspace->slug}/clients/{$client->id}")
            ->assertNotFound();
    }

    public function test_unknown_or_malformed_identifiers_are_not_found(): void
    {
        Client::factory()->for($this->workspace)->create();

        foreach ([(string) Str::ulid(), 'not-a-ulid', '01ARZ3NDEKTSV4RRFFQ69G5FA', "1' OR '1'='1"] as $identifier) {
            $this->actingAs($this->owner)
                ->get('/'.$this->workspace->slug.'/clients/'.rawurlencode($identifier))
                ->assertNotFound();
        }
    }

    public function test_a_public_id_from_another_workspace_is_not_found(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();
        $elsewhere = Client::factory()->for($otherWorkspace)->create();

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', ['workspace' => $this->workspace->slug, 'client' => $elsewhere->public_id]))
            ->assertNotFound();
    }

    public function test_the_public_id_cannot_be_changed(): void
    {
        $client = Client::factory()->for($this->workspace)->create();

        $this->expectException(LogicException::class);

        $client->forceFill(['public_id' => (string) Str::ulid()])->save();
    }

    public function test_public_ids_are_unique_in_the_database(): void
    {
        $client = Client::factory()->for($this->workspace)->create();

        $this->expectException(QueryException::class);

        Client::factory()->for($this->workspace)->create(['public_id' => $client->public_id]);
    }

    public function test_props_never_contain_the_database_id_or_storage_path(): void
    {
        $client = Client::factory()->rocketFlood()->for($this->workspace)->create();
        $client->forceFill(['logo_path' => "workspaces/{$this->workspace->id}/clients/{$client->public_id}/secret.png"])->save();

        $expectedKeys = ['public_id', 'name', 'billing_email', 'cc_emails', 'currency', 'address', 'tax_id', 'logo_url', 'archived_at', 'created_at'];

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.index', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('clients.0', fn ($row) => array_keys(collect($row)->all()) === $expectedKeys))
            ->assertDontSee('secret.png');

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $client]))
            ->assertInertia(fn ($page) => $page->where('client', fn ($row) => array_keys(collect($row)->all()) === $expectedKeys))
            ->assertDontSee('secret.png');
    }
}
