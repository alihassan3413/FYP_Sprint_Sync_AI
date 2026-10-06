<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkspaceTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_workspace_starts_in_its_owners_timezone(): void
    {
        $owner = User::factory()->create(['timezone' => 'Asia/Karachi']);

        $this->actingAs($owner)
            ->post(route('workspace.store'), ['name' => 'NBT Hub', 'slug' => 'nbt-hub'])
            ->assertRedirect();

        $this->assertSame('Asia/Karachi', Workspace::query()->where('slug', 'nbt-hub')->value('timezone'));
    }

    public function test_an_owner_without_a_timezone_gets_the_application_default(): void
    {
        $owner = User::factory()->create(['timezone' => null]);

        $this->actingAs($owner)
            ->post(route('workspace.store'), ['name' => 'NBT Hub', 'slug' => 'nbt-hub'])
            ->assertRedirect();

        $this->assertSame(config('app.timezone'), Workspace::query()->where('slug', 'nbt-hub')->value('timezone'));
    }

    public function test_a_fresh_model_defaults_to_utc_without_reloading(): void
    {
        $this->assertSame('UTC', (new Workspace)->timezone);
    }

    public function test_the_settings_page_shares_the_workspace_timezone(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create(['timezone' => 'Asia/Karachi']);

        $this->actingAs($owner)
            ->get(route('workspace.settings', $workspace))
            ->assertInertia(fn ($page) => $page->where('workspaceProfile.timezone', 'Asia/Karachi'));
    }

    public function test_an_owner_can_change_the_timezone_and_it_is_audited(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create(['timezone' => 'UTC']);

        $this->actingAs($owner)
            ->put(route('workspace.update', $workspace), [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'timezone' => 'Asia/Karachi',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Asia/Karachi', $workspace->fresh()->timezone);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => AuditAction::WORKSPACE_TIMEZONE_CHANGED->value,
        ]);
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create(['timezone' => 'UTC']);

        $this->actingAs($owner)
            ->put(route('workspace.update', $workspace), [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('UTC', $workspace->fresh()->timezone);
    }

    public function test_renaming_without_a_timezone_keeps_the_current_one(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create(['timezone' => 'Asia/Karachi']);

        $this->actingAs($owner)
            ->put(route('workspace.update', $workspace), ['name' => 'Renamed', 'slug' => $workspace->slug])
            ->assertSessionHasNoErrors();

        $this->assertSame('Asia/Karachi', $workspace->fresh()->timezone);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::WORKSPACE_TIMEZONE_CHANGED->value]);
    }

    public function test_a_member_cannot_change_the_timezone(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->withMember($member, UserRole::MEMBER)->create(['timezone' => 'UTC']);

        $this->actingAs($member)
            ->put(route('workspace.update', $workspace), [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'timezone' => 'Asia/Karachi',
            ])
            ->assertForbidden();

        $this->assertSame('UTC', $workspace->fresh()->timezone);
    }
}
