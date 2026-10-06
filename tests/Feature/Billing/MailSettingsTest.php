<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use App\Modules\Billing\Support\SmtpHostPolicy;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\FakesWorkspaceMail;
use Tests\TestCase;

/**
 * Settings → Email delivery: the workspace's own SMTP account. The password
 * is encrypted at rest and never leaves the server again.
 */
final class MailSettingsTest extends TestCase
{
    use FakesWorkspaceMail, RefreshDatabase;

    private const PASSWORD = 'super-secret-smtp-pass';

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->owner = User::factory()->create(['email' => 'owner@sprintsync.test']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->fakeWorkspaceMail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'billing@sprintsync.test',
            'password' => self::PASSWORD,
            'from_email' => 'billing@sprintsync.test',
            'from_name' => 'SprintSync QA',
            'reply_to_email' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function save(array $details, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.mail-settings.edit', $this->workspace))
            ->put(route('workspace.mail-settings.update', $this->workspace), $details);
    }

    private function sendTest(string $recipient = 'owner@sprintsync.test', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.mail-settings.edit', $this->workspace))
            ->post(route('workspace.mail-settings.test', $this->workspace), ['recipient' => $recipient]);
    }

    private function member(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    public function test_the_owner_saves_settings_and_the_password_is_encrypted_at_rest(): void
    {
        $this->save($this->details())->assertSessionHasNoErrors()->assertRedirect();

        $settings = WorkspaceMailSettings::query()->sole();
        $this->assertSame('smtp.example.com', $settings->host);
        $this->assertSame(self::PASSWORD, $settings->password);

        $stored = (string) DB::table('workspace_mail_settings')->value('password');
        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, $stored);
    }

    public function test_the_password_is_never_sent_back_to_the_browser(): void
    {
        $this->save($this->details());

        $response = $this->actingAs($this->owner)
            ->get(route('workspace.mail-settings.edit', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/settings/EmailDelivery')
                ->where('hasPassword', true)
                ->where('settings.host', 'smtp.example.com')
                ->missing('settings.password'));

        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
        $this->assertArrayNotHasKey('password', WorkspaceMailSettings::query()->sole()->toArray());
    }

    public function test_a_blank_password_keeps_the_saved_one(): void
    {
        $this->save($this->details());
        $this->save($this->details(['password' => '', 'from_name' => 'SprintSync Billing']))->assertSessionHasNoErrors();

        $settings = WorkspaceMailSettings::query()->sole();
        $this->assertSame(self::PASSWORD, $settings->password);
        $this->assertSame('SprintSync Billing', $settings->from_name);
    }

    public function test_a_password_is_required_with_a_username_the_first_time(): void
    {
        $this->save($this->details(['password' => '']))->assertSessionHasErrors('password');
        $this->assertSame(0, WorkspaceMailSettings::query()->count());
    }

    public function test_a_failed_save_never_echoes_the_password(): void
    {
        $this->save($this->details(['host' => 'not a host!', 'password' => 'typed-secret']))->assertSessionHasErrors('host');

        $this->assertNull(session()->getOldInput('password'));
        $this->assertStringNotContainsString('typed-secret', json_encode(session()->all()));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidDetails(): array
    {
        return [
            'missing host' => [['host' => ''], 'host'],
            'bad host' => [['host' => 'smtp example com'], 'host'],
            'url as host' => [['host' => 'http://smtp.example.com'], 'host'],
            'odd port' => [['port' => 6379], 'port'],
            'unknown encryption' => [['encryption' => 'starttls-maybe'], 'encryption'],
            'bad from email' => [['from_email' => 'nope'], 'from_email'],
            'missing from name' => [['from_name' => ''], 'from_name'],
            'bad reply-to' => [['reply_to_email' => 'nope'], 'reply_to_email'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidDetails')]
    public function test_settings_are_validated_on_the_server(array $overrides, string $field): void
    {
        $this->save($this->details($overrides))->assertSessionHasErrors($field);
        $this->assertSame(0, WorkspaceMailSettings::query()->count());
    }

    public function test_production_refuses_internal_hosts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertNotNull(SmtpHostPolicy::hostProblem('localhost'));
        $this->assertNotNull(SmtpHostPolicy::hostProblem('127.0.0.1'));
        $this->assertNotNull(SmtpHostPolicy::hostProblem('169.254.169.254'));
        $this->assertNotContains(1025, SmtpHostPolicy::allowedPorts());
    }

    public function test_changing_the_connection_clears_the_tested_mark(): void
    {
        $this->save($this->details());
        $this->sendTest();
        $this->assertNotNull(WorkspaceMailSettings::query()->sole()->verified_at);

        $this->save($this->details(['password' => '', 'reply_to_email' => 'accounts@sprintsync.test']));
        $this->assertNotNull(WorkspaceMailSettings::query()->sole()->verified_at, 'reply-to is not part of the connection');

        $this->save($this->details(['password' => '', 'host' => 'smtp.other-provider.com']));
        $this->assertNull(WorkspaceMailSettings::query()->sole()->verified_at);
    }

    public function test_saving_is_audited_without_the_password(): void
    {
        $this->save($this->details());
        $this->save($this->details());

        $entry = AuditLog::query()->where('action', AuditAction::MAIL_SETTINGS_SAVED->value)->sole();
        $this->assertContains('password', $entry->metadata['changed']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($entry->toArray()));
    }

    public function test_a_test_email_goes_through_the_workspace_server(): void
    {
        Mail::fake();
        $this->save($this->details());

        $this->sendTest()->assertSessionHasNoErrors()->assertSessionHas('success', 'Test email sent successfully.');

        $this->assertCount(1, $this->smtp->sent);
        $email = $this->smtp->sent[0];
        $this->assertSame('SprintSync email delivery test', $email->getSubject());
        $this->assertSame('owner@sprintsync.test', $email->getTo()[0]->getAddress());
        $this->assertSame('billing@sprintsync.test', $email->getFrom()[0]->getAddress());
        Mail::assertNothingOutgoing();

        $settings = WorkspaceMailSettings::query()->sole();
        $this->assertNotNull($settings->verified_at);
        $this->assertNull($settings->last_test_error);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::MAIL_TEST_SUCCEEDED->value)->count());
    }

    public function test_a_failed_test_shows_a_safe_reason(): void
    {
        Log::spy();
        $this->save($this->details());
        $this->fakeWorkspaceMail('fail', 'Failed to authenticate on SMTP server with username "billing@sprintsync.test" using password "'.self::PASSWORD.'": 535 5.7.8');

        $this->sendTest()->assertSessionHasErrors(['recipient' => "Couldn't send the test email. The email server rejected the username or password."]);

        $settings = WorkspaceMailSettings::query()->sole();
        $this->assertNull($settings->verified_at);
        $this->assertSame('The email server rejected the username or password.', $settings->last_test_error);

        $entry = AuditLog::query()->where('action', AuditAction::MAIL_TEST_FAILED->value)->sole();
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($entry->toArray()));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(session()->all()));
        Log::shouldNotHaveReceived('error');
    }

    public function test_test_emails_are_rate_limited(): void
    {
        $this->save($this->details());

        for ($i = 0; $i < 5; $i++) {
            $this->sendTest()->assertRedirect();
        }

        $this->sendTest()->assertTooManyRequests();
        $this->assertCount(5, $this->smtp->sent);
    }

    public function test_a_test_needs_saved_settings(): void
    {
        $this->sendTest()->assertSessionHasErrors(['recipient' => 'Save your email settings first.']);
        $this->assertCount(0, $this->smtp->sent);
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest client' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_can_see_or_change_email_delivery(UserRole $role): void
    {
        $this->save($this->details());
        $user = $this->member($role);

        $this->actingAs($user)->get(route('workspace.mail-settings.edit', $this->workspace))->assertForbidden();
        $this->save($this->details(['host' => 'evil.example.com']), $user)->assertForbidden();
        $this->sendTest('attacker@example.com', $user)->assertForbidden();

        $this->assertSame('smtp.example.com', WorkspaceMailSettings::query()->sole()->host);
        $this->assertCount(0, $this->smtp->sent);
    }

    public function test_other_workspaces_get_not_found(): void
    {
        $this->save($this->details());
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.mail-settings.edit', $this->workspace))->assertNotFound();
        $this->save($this->details(['host' => 'evil.example.com']), $outsider)->assertNotFound();
        $this->sendTest('attacker@example.com', $outsider)->assertNotFound();

        $this->assertSame('smtp.example.com', WorkspaceMailSettings::query()->sole()->host);
    }

    public function test_the_settings_card_shows_whether_email_is_connected(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.settings', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('emailDeliveryConnected', false));

        $this->save($this->details());
        $this->sendTest();

        $this->actingAs($this->owner)
            ->get(route('workspace.settings', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('emailDeliveryConnected', true));
    }
}
