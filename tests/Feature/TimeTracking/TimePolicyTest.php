<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\TimeTracking\Models\TimePolicy;
use App\Modules\TimeTracking\Support\TimePolicies;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\Feature\TimeTracking\Concerns\ConfiguresTimePolicy;
use Tests\TestCase;

/**
 * The owner's time rules: optional, off by default, versioned so history is
 * always judged by the rules of its own time.
 */
final class TimePolicyTest extends TestCase
{
    use BuildsTimeTeam, ConfiguresTimePolicy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->setUpTimeTeam();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.time.settings.edit', $this->workspace))
            ->put(route('workspace.time.settings.policy', $this->workspace), [
                'idle_enabled' => true,
                'idle_minutes' => 5,
                'weekly_target_hours' => '40',
                'lunch_enabled' => true,
                'lunch_minutes' => 60,
                'overtime_mode' => 'track_only',
                'integrity_enabled' => false,
                ...$data,
            ]);
    }

    public function test_everything_is_off_until_the_owner_turns_it_on(): void
    {
        $policy = app(TimePolicies::class)->at($this->workspace->id);

        $this->assertFalse($policy->idleEnabled);
        $this->assertFalse($policy->lunchEnabled);
        $this->assertFalse($policy->integrityEnabled);
        $this->assertNull($policy->weeklyTargetSeconds);
    }

    public function test_the_owner_saves_the_policy(): void
    {
        $this->save()->assertSessionHasNoErrors();

        $policy = app(TimePolicies::class)->at($this->workspace->id);
        $this->assertTrue($policy->idleEnabled);
        $this->assertSame(300, $policy->idleSeconds);
        $this->assertSame(144000, $policy->weeklyTargetSeconds);
        $this->assertSame(3600, $policy->lunchAllowanceSeconds);

        $entry = AuditLog::query()->where('action', AuditAction::TIME_POLICY_CHANGED->value)->sole();
        $this->assertContains('idle_enabled', $entry->metadata['changed']);
    }

    public function test_saving_the_same_rules_again_adds_no_version(): void
    {
        $this->save();
        $this->save();

        $this->assertSame(1, TimePolicy::query()->count());
    }

    public function test_a_change_never_rewrites_history(): void
    {
        $this->save(['idle_minutes' => 10]);
        $october = CarbonImmutable::parse('2026-10-20 12:00:00');

        $this->travelTo('2026-11-01 08:00:00');
        $this->save(['idle_minutes' => 5]);

        $policies = app(TimePolicies::class);
        $this->assertSame(600, $policies->at($this->workspace->id, $october)->idleSeconds, "October keeps October's rule");
        $this->assertSame(300, $policies->at($this->workspace->id)->idleSeconds);
        $this->assertFalse($policies->at($this->workspace->id, CarbonImmutable::parse('2026-10-01'))->idleEnabled, 'before any policy: off');

        $this->expectException(\LogicException::class);
        TimePolicy::query()->first()->update(['idle_seconds' => 1]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): array
    {
        return [
            'idle too short' => [['idle_minutes' => 1], 'idle_minutes'],
            'idle missing' => [['idle_minutes' => null], 'idle_minutes'],
            'lunch too long' => [['lunch_minutes' => 600], 'lunch_minutes'],
            'bad target' => [['weekly_target_hours' => '40.555'], 'weekly_target_hours'],
            'unknown overtime mode' => [['overtime_mode' => 'pay_double'], 'overtime_mode'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalid')]
    public function test_the_policy_is_validated(array $data, string $field): void
    {
        $this->save($data)->assertSessionHasErrors($field);
        $this->assertSame(0, TimePolicy::query()->count());
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_manages_time_settings(UserRole $role): void
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->actingAs($user)->get(route('workspace.time.settings.edit', $this->workspace))->assertForbidden();
        $this->save([], $user)->assertForbidden();
        $this->actingAs($user)->post(route('workspace.time.settings.time-off', $this->workspace), ['kind' => 'holiday', 'name' => 'X', 'start_date' => '2026-12-25', 'hours' => '8', 'idempotency_key' => $this->key()])->assertForbidden();
        $this->assertSame(0, TimePolicy::query()->count());
    }

    public function test_other_workspaces_get_not_found(): void
    {
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.time.settings.edit', $this->workspace))->assertNotFound();
        $this->save([], $outsider)->assertNotFound();
    }

    public function test_members_see_the_policy_that_applies_to_them(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300, 'integrity_enabled' => true]);

        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('policy.idle_enabled', true)
                ->where('policy.idle_seconds', 300)
                ->where('policy.integrity_enabled', true)
                ->where('reviews', [])
                ->where('team', []));
    }

    public function test_the_settings_page_shows_the_current_rules(): void
    {
        $this->save();

        $this->actingAs($this->owner)->get(route('workspace.time.settings.edit', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/settings')
                ->where('policy.idle_minutes', 5)
                ->where('policy.lunch_minutes', 60)
                ->where('policy.weekly_target_hours', '40')
                ->has('history', 1)
                ->has('people', 6));
    }
}
