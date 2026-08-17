<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AccountAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $this->workspace = Workspace::factory()->ownedBy($this->user)->create();
        $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function accountLogs(): Collection
    {
        return AuditLog::query()
            ->whereIn('action', array_map(
                fn (AuditAction $action) => $action->value,
                array_filter(AuditAction::cases(), fn (AuditAction $action) => $action->isGlobal()),
            ))
            ->get();
    }

    public function test_a_profile_update_records_one_global_event_with_only_changed_field_names(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.update'), ['name' => 'Ada King', 'email' => 'ada@example.com'])
            ->assertRedirect(route('profile.edit'));

        $logs = $this->accountLogs();

        $this->assertCount(1, $logs);

        $log = $logs->first();

        $this->assertSame(AuditAction::ACCOUNT_PROFILE_UPDATED->value, $log->action);
        $this->assertNull($log->workspace_id);
        $this->assertNull($log->project_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame(['changed_fields' => ['name']], $log->metadata);
        $this->assertStringNotContainsString('ada@example.com', json_encode($log->metadata));
    }

    public function test_a_no_op_profile_save_records_nothing(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.update'), ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])
            ->assertRedirect(route('profile.edit'));

        $this->assertCount(0, $this->accountLogs());
    }

    public function test_a_password_change_records_a_global_event_without_sensitive_metadata(): void
    {
        $this->actingAs($this->user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertRedirect();

        $logs = $this->accountLogs();

        $this->assertCount(1, $logs);

        $log = $logs->first();

        $this->assertSame(AuditAction::ACCOUNT_PASSWORD_CHANGED->value, $log->action);
        $this->assertNull($log->workspace_id);
        $this->assertNull($log->project_id);

        $serialised = json_encode([$log->description, $log->metadata]);

        $this->assertStringNotContainsString('new-password-1', $serialised);
        $this->assertStringNotContainsString('password', strtolower((string) json_encode($log->metadata)));
        $this->assertStringNotContainsString('$2y$', $serialised);
    }

    public function test_an_avatar_upload_records_a_global_event_without_the_stored_path(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.jpg')])
            ->assertRedirect(route('profile.edit'));

        $logs = $this->accountLogs();

        $this->assertCount(1, $logs);

        $log = $logs->first();

        $this->assertSame(AuditAction::ACCOUNT_AVATAR_UPDATED->value, $log->action);
        $this->assertNull($log->workspace_id);
        $this->assertSame([], $log->metadata);
        $this->assertStringNotContainsString('avatars/', (string) json_encode([$log->description, $log->metadata]));
    }

    public function test_an_avatar_removal_records_a_global_event(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.jpg')]);

        $this->actingAs($this->user)
            ->delete(route('profile.avatar.destroy'))
            ->assertRedirect(route('profile.edit'));

        $logs = $this->accountLogs();

        $this->assertCount(2, $logs);
        $this->assertSame(AuditAction::ACCOUNT_AVATAR_REMOVED->value, $logs->last()->action);
        $this->assertNull($logs->last()->workspace_id);
    }

    public function test_removing_an_absent_avatar_records_nothing(): void
    {
        $this->actingAs($this->user)
            ->delete(route('profile.avatar.destroy'))
            ->assertRedirect(route('profile.edit'));

        $this->assertCount(0, $this->accountLogs());
    }

    public function test_account_deletion_leaves_a_durable_human_readable_audit_row(): void
    {
        $this->actingAs($this->user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);

        $log = AuditLog::query()->where('action', AuditAction::ACCOUNT_DELETED->value)->firstOrFail();

        $this->assertNull($log->workspace_id);
        $this->assertNull($log->project_id);
        $this->assertSame('Ada Lovelace (ada@example.com) deleted their account.', $log->description);
        $this->assertNull($log->user);
    }

    public function test_global_account_events_never_appear_in_a_workspace_audit_log(): void
    {
        $this->actingAs($this->user)
            ->patch(route('profile.update'), ['name' => 'Ada King', 'email' => 'ada@example.com']);

        $this->actingAs($this->user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ]);

        $this->actingAs($this->user)
            ->put(route('workspace.update', $this->workspace), [
                'name' => 'Renamed Workspace',
                'slug' => 'renamed-workspace',
            ])
            ->assertRedirect();

        $this->assertCount(2, $this->accountLogs());

        $this->actingAs($this->user)
            ->get(route('workspace.audit.index', ['workspace' => 'renamed-workspace']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action_label', 'Workspace renamed')
                ->where('categories', ['Workspace', 'Team', 'Projects', 'Tasks', 'Meetings']))
            ->assertDontSee('Profile updated')
            ->assertDontSee('Password changed');
    }

    public function test_account_events_of_another_user_never_appear_in_a_workspace_audit_log(): void
    {
        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id, ['role' => UserRole::MEMBER->value]);
        $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->actingAs($member)
            ->patch(route('profile.update'), ['name' => 'Renamed', 'email' => $member->email]);

        $this->assertCount(1, $this->accountLogs());

        $this->actingAs($this->user)
            ->get(route('workspace.audit.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 0))
            ->assertDontSee('Profile updated');
    }
}
