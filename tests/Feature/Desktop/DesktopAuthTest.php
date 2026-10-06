<?php

declare(strict_types=1);

namespace Tests\Feature\Desktop;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Desktop\Models\DesktopDevice;
use App\Modules\Desktop\Support\DesktopTokens;
use App\Modules\TimeTracking\Actions\SaveTimePolicyAction;
use App\Modules\TimeTracking\Data\OvertimeMode;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\TestCase;

/**
 * SprintSync Desktop sign-in: a revocable, hashed per-device token that only
 * works on the desktop API, with workspace membership still checked.
 */
final class DesktopAuthTest extends TestCase
{
    use BuildsTimeTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->setUpTimeTeam();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function signIn(array $data = []): TestResponse
    {
        return $this->postJson(route('desktop.sign-in'), [
            'email' => $this->member->email,
            'password' => 'password',
            'device_name' => "Usman's MacBook Pro",
            'platform' => 'macos',
            ...$data,
        ]);
    }

    private function token(): string
    {
        return $this->signIn()->json('token');
    }

    private function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    public function test_signing_in_issues_a_hashed_device_token(): void
    {
        $response = $this->signIn()
            ->assertCreated()
            ->assertJsonPath('device.name', "Usman's MacBook Pro")
            ->assertJsonPath('device.platform', 'macos')
            ->assertJsonPath('user.email', $this->member->email)
            ->assertJsonPath('workspaces.0.slug', $this->workspace->slug)
            ->assertJsonPath('workspaces.0.has_profile', true);

        $token = $response->json('token');
        $this->assertStringStartsWith('ssd_', $token);
        $this->assertGreaterThan(60, strlen($token));

        $device = DesktopDevice::query()->sole();
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DesktopDevice::query()->toBase()->get()), 'only the hash is stored');
        $this->assertArrayNotHasKey('token_hash', $device->toArray());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertGuest('web');
    }

    public function test_the_token_works_on_the_time_api(): void
    {
        $token = $this->token();

        $this->api('GET', route('desktop.time.overview', $this->workspace), $token)
            ->assertOk()
            ->assertJsonPath('has_profile', true)
            ->assertJsonPath('projects.0.public_id', $this->project->public_id)
            ->assertJsonPath('projects.0.tasks.0.title', 'Flood Quote Workflow')
            ->assertJsonPath('timer', null)
            ->assertJsonPath('today_seconds', 0)
            ->assertJsonMissingPath('projects.0.id');

        $this->api('POST', route('desktop.time.timer.start', $this->workspace), $token, ['project' => $this->project->public_id, 'operation_key' => $this->key()])
            ->assertOk()
            ->assertJsonPath('timer.state', 'running');

        $this->assertSame(1, TimeSession::query()->count());
    }

    public function test_wrong_or_missing_tokens_are_refused(): void
    {
        $this->token();

        $this->getJson(route('desktop.me'))->assertUnauthorized();
        $this->api('GET', route('desktop.me'), 'ssd_not-a-real-token')->assertUnauthorized();
        $this->api('GET', route('desktop.time.overview', $this->workspace), 'nonsense')->assertUnauthorized();
    }

    public function test_signing_out_revokes_the_token(): void
    {
        $token = $this->token();

        $this->api('POST', route('desktop.sign-out'), $token)->assertOk();
        $this->api('GET', route('desktop.me'), $token)->assertUnauthorized();
        $this->api('GET', route('desktop.time.overview', $this->workspace), $token)->assertUnauthorized();

        $this->assertNotNull(DesktopDevice::query()->sole()->revoked_at);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ACCOUNT_DESKTOP_SIGNED_OUT->value)->count());
    }

    public function test_signing_out_on_one_device_keeps_the_other_signed_in(): void
    {
        $mac = $this->token();
        $windows = $this->signIn(['device_name' => 'Office PC', 'platform' => 'windows'])->json('token');

        $this->api('POST', route('desktop.sign-out'), $mac);

        $this->api('GET', route('desktop.me'), $windows)->assertOk();
    }

    public function test_an_unused_token_expires(): void
    {
        $token = $this->token();

        $this->travel(DesktopTokens::IDLE_DAYS + 1)->days();

        $this->api('GET', route('desktop.me'), $token)->assertUnauthorized();
    }

    public function test_using_a_token_records_when_it_was_last_used(): void
    {
        $token = $this->token();
        $this->travel(2)->hours();

        $this->api('GET', route('desktop.me'), $token);

        $this->assertSame('2026-10-12 11:00:00', DesktopDevice::query()->sole()->last_used_at->toDateTimeString());
    }

    public function test_wrong_passwords_are_refused_and_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->signIn(['password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->signIn()->assertTooManyRequests();
        $this->assertSame(0, DesktopDevice::query()->count());
    }

    public function test_unverified_accounts_cannot_sign_in(): void
    {
        $this->member->forceFill(['email_verified_at' => null])->save();

        $this->signIn()->assertUnprocessable();
        $this->assertSame(0, DesktopDevice::query()->count());
    }

    public function test_sign_in_is_validated(): void
    {
        $this->signIn(['platform' => 'amiga'])->assertJsonValidationErrors('platform');
        $this->signIn(['device_name' => str_repeat('x', 101)])->assertJsonValidationErrors('device_name');
    }

    public function test_another_workspace_is_not_found_with_a_valid_token(): void
    {
        $token = $this->token();
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();

        $this->api('GET', route('desktop.time.overview', $other), $token)->assertNotFound();
        $this->api('POST', route('desktop.time.timer.start', $other), $token, ['project' => $this->project->public_id, 'operation_key' => $this->key()])->assertNotFound();
    }

    public function test_client_guests_get_no_time_api(): void
    {
        $guest = User::factory()->create(['password' => bcrypt('password')]);
        $this->workspace->users()->attach($guest->id, ['role' => UserRole::CLIENT->value]);

        $response = $this->signIn(['email' => $guest->email])->assertCreated();
        $this->assertSame([], $response->json('workspaces'), 'no workspaces to track time in');

        $this->api('GET', route('desktop.time.overview', $this->workspace), $response->json('token'))->assertForbidden();
    }

    public function test_the_token_does_not_open_the_web_app(): void
    {
        $token = $this->token();

        $this->withToken($token)->get(route('workspace.time.index', $this->workspace))->assertRedirect(route('login'));
        $this->withToken($token)->postJson(route('workspace.time.timer.start', $this->workspace), ['project' => $this->project->public_id, 'operation_key' => $this->key()])->assertUnauthorized();
    }

    public function test_tokens_never_reach_the_logs(): void
    {
        Log::spy();
        $token = $this->token();
        $this->api('GET', route('desktop.me'), $token);
        $this->api('POST', route('desktop.sign-out'), $token);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
        $this->assertStringNotContainsString($token, AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString(Str::after($token, 'ssd_'), AuditLog::query()->get()->toJson());
    }

    public function test_the_desktop_gets_the_server_policy_and_can_take_lunch_and_idle_pause(): void
    {
        app(SaveTimePolicyAction::class)->handle($this->workspace, [
            'idle_enabled' => true, 'idle_seconds' => 120, 'weekly_target_seconds' => null, 'lunch_enabled' => true,
            'lunch_allowance_seconds' => 3600, 'overtime_mode' => OvertimeMode::TrackOnly, 'integrity_enabled' => true,
        ], $this->owner);
        $token = $this->token();

        $this->api('GET', route('desktop.time.overview', $this->workspace), $token)
            ->assertOk()
            ->assertJsonPath('policy.idle_enabled', true)
            ->assertJsonPath('policy.idle_seconds', 120)
            ->assertJsonPath('policy.lunch_allowance_seconds', 3600)
            ->assertJsonPath('policy.integrity_enabled', true)
            ->assertJsonPath('idle_periods', []);

        $session = $this->api('POST', route('desktop.time.timer.start', $this->workspace), $token, ['project' => $this->project->public_id, 'operation_key' => $this->key()])->json('timer.public_id');
        $this->travel(1)->hours();
        $this->api('POST', route('desktop.time.timer.lunch-start', $this->workspace), $token, ['operation_key' => $this->key(), 'session' => $session])->assertOk()->assertJsonPath('timer.state', 'lunch');
        $this->travel(30)->minutes();
        $this->api('POST', route('desktop.time.timer.lunch-end', $this->workspace), $token, ['operation_key' => $this->key(), 'session' => $session])->assertOk()->assertJsonPath('timer.state', 'running');
        $this->travel(10)->minutes();
        $this->api('POST', route('desktop.time.timer.idle-pause', $this->workspace), $token, [
            'operation_key' => $this->key(), 'session' => $session,
            'idle_since' => now()->subMinutes(3)->toIso8601String(), 'occurred_at' => now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('timer.pause_reason', 'idle');

        $this->api('GET', route('desktop.time.overview', $this->workspace), $token)
            ->assertJsonPath('idle_periods.0.decision', 'pending')
            ->assertJsonPath('today_breakdown.lunch_paid', 1800)
            ->assertJsonPath('timer.state', 'paused');

        $this->api('POST', route('desktop.time.activity', $this->workspace), $token, [
            'window_start' => now()->subMinutes(10)->toIso8601String(), 'window_end' => now()->toIso8601String(), 'resets' => 20, 'mean_gap_ms' => 30000, 'variation_bp' => 0,
        ])->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame(1, TimeReview::query()->where('kind', 'activity')->count());
    }
}
