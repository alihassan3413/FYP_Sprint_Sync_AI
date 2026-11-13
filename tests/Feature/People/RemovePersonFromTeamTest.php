<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\TestCase;

final class RemovePersonFromTeamTest extends TestCase
{
    use BuildsTimeTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTimeTeam();
    }

    private function remove(Person $person, ?User $as = null): TestResponse
    {
        return $this->withHeader('X-Inertia', 'true')
            ->actingAs($as ?? $this->owner)
            ->delete(route('workspace.people.destroy', [$this->workspace, $person]));
    }

    private function wahab(): Person
    {
        return $this->team['Abdul Wahab'];
    }

    public function test_the_owner_deletes_a_teammate_and_they_disappear_everywhere(): void
    {
        $person = $this->wahab();
        $entry = TimeEntry::query()->forceCreate([
            'workspace_id' => $this->workspace->id,
            'person_id' => $person->id,
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'source' => 'manual',
            'duration_seconds' => 3600,
            'period_start' => '2026-11-02',
            'period_end' => '2026-11-02',
            'timezone' => 'UTC',
            'billable' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->remove($person)->assertRedirect(route('workspace.people.index', ['workspace' => $this->workspace->slug]))->assertSessionHas('success');
        $this->flushHeaders();

        $this->assertNotNull($person->fresh()->removed_at);
        $this->assertSame($this->owner->id, $person->fresh()->removed_by);
        $this->assertModelExists($entry);

        $names = fn (array $rows) => array_column($rows, 'name');

        $this->actingAs($this->owner)->get(route('workspace.people.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('members', fn ($members) => ! in_array('Abdul Wahab', collect($members)->pluck('name')->all(), true)));

        $this->actingAs($this->owner)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('people', fn ($people) => ! in_array('Abdul Wahab', collect($people)->pluck('name')->all(), true))
                ->where('team', fn ($team) => ! in_array('Abdul Wahab', collect($team)->pluck('name')->all(), true)));

        $this->actingAs($this->owner)->get(route('workspace.time.settings.edit', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('people', fn ($people) => ! in_array('Abdul Wahab', $names($people->toArray()), true)));

        $this->actingAs($this->owner)->get(route('workspace.people.show', [$this->workspace, $person]))->assertNotFound();
        $this->actingAs($this->owner)->get(route('workspace.time.index', [$this->workspace, 'person' => $person->public_id]))->assertNotFound();

        $log = AuditLog::query()->where('action', AuditAction::PERSON_REMOVED->value)->sole();
        $this->assertStringContainsString('Abdul Wahab', $log->description);
    }

    public function test_someone_with_access_must_lose_access_first_and_their_login_is_never_touched(): void
    {
        $usman = $this->usman();

        $this->remove($usman)->assertSessionHas('error', fn (array $error) => str_contains((string) json_encode($error), 'still has SprintSync access'));
        $this->flushHeaders();
        $this->assertNull($usman->fresh()->removed_at);

        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($this->owner)->delete(route('workspace.members.destroy', [$this->workspace, $this->member]))->assertSessionHasNoErrors();
        $this->remove($usman->fresh())->assertSessionHas('success');
        $this->flushHeaders();

        $this->assertNotNull($usman->fresh()->removed_at);
        $this->assertNull($usman->fresh()->user_id);
        $this->assertModelExists($this->member);
        $this->assertTrue($other->users()->whereKey($this->member->id)->exists(), 'their other workspace is untouched');
    }

    public function test_a_pending_invitation_must_be_cancelled_first(): void
    {
        $person = $this->wahab();
        $person->forceFill(['email' => 'wahab@example.test'])->save();
        WorkspaceInvitation::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'Wahab@example.test', 'invited_by' => $this->owner->id]);

        $this->remove($person)->assertSessionHas('error', fn (array $error) => str_contains((string) json_encode($error), 'pending invitation'));
        $this->assertNull($person->fresh()->removed_at);
    }

    public function test_an_active_recurring_invoice_blocks_deletion_until_it_is_paused(): void
    {
        $person = $this->wahab();
        $client = Client::factory()->for($this->workspace)->create(['name' => 'Halden & Co.']);
        $plan = BillingPlan::factory()->for($client)->create(['workspace_id' => $this->workspace->id, 'name' => 'Monthly support']);
        $plan->lines()->create(['position' => 0, 'person_id' => $person->id, 'description' => 'Abdul Wahab', 'unit_price_minor' => 500]);

        $this->remove($person)->assertSessionHas('error', fn (array $error) => str_contains((string) json_encode($error), 'Monthly support'));
        $this->flushHeaders();
        $this->assertNull($person->fresh()->removed_at);

        $plan->forceFill(['paused_at' => now()])->save();

        $this->remove($person)->assertSessionHas('success');
        $this->assertNotNull($person->fresh()->removed_at);
    }

    public function test_a_timer_left_running_is_stopped_when_they_are_deleted(): void
    {
        $this->startTimer()->assertOk();
        $this->actingAs($this->owner)->delete(route('workspace.members.destroy', [$this->workspace, $this->member]))->assertSessionHasNoErrors();
        $this->travel(2)->hours();

        $this->remove($this->usman()->fresh())->assertSessionHas('success');

        $session = TimeSession::query()->where('person_id', $this->usman()->id)->sole();
        $this->assertSame(TimerState::Stopped, $session->state);
        $this->assertSame(1, TimeEntry::query()->where('time_session_id', $session->id)->count());
    }

    public function test_only_the_owner_can_delete_and_never_their_own_profile(): void
    {
        $admin = User::factory()->create();
        $this->workspace->users()->attach($admin->id, ['role' => UserRole::ADMIN->value]);

        $this->actingAs($admin)->delete(route('workspace.people.destroy', [$this->workspace, $this->wahab()]))->assertForbidden();
        $this->assertNull($this->wahab()->fresh()->removed_at);

        $this->remove($this->team['Ali Hassan'])->assertSessionHas('error', fn (array $error) => str_contains((string) json_encode($error), 'own team profile'));
        $this->assertNull($this->team['Ali Hassan']->fresh()->removed_at);
    }

    public function test_the_same_email_can_join_the_team_again_as_a_new_profile(): void
    {
        $person = $this->wahab();
        $person->forceFill(['email' => 'wahab@example.test'])->save();

        $this->remove($person)->assertSessionHas('success');
        $this->flushHeaders();

        $this->actingAs($this->owner)->post(route('workspace.people.store', $this->workspace), ['name' => 'Abdul Wahab', 'email' => 'wahab@example.test'])->assertSessionHasNoErrors();

        $this->assertSame(2, Person::query()->where('email', 'wahab@example.test')->count());
        $this->assertSame(1, $this->workspace->people()->where('email', 'wahab@example.test')->count());
    }
}
