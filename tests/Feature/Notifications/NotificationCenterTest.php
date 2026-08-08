<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->user)->create();
    }

    private function notify(User $user, string $title = 'Task assigned to you', string $url = 'https://example.test/project'): void
    {
        $user->notify(new TaskAssignedNotification(
            projectName: 'Demo project',
            taskTitle: 'Write specs',
            assignedByName: 'Someone',
            url: $url,
        ));
    }

    public function test_unread_count_reflects_unread_notifications(): void
    {
        $this->notify($this->user);
        $this->notify($this->user);

        $this->actingAs($this->user)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('notifications.unread_count', 2));
    }

    public function test_notification_data_is_exposed_via_the_shared_prop(): void
    {
        $this->notify($this->user, url: 'https://example.test/projects/1');

        $this->actingAs($this->user)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.recent.0.title', 'Task assigned to you')
                ->where('notifications.recent.0.url', 'https://example.test/projects/1')
                ->where('notifications.recent.0.read_at', null));
    }

    public function test_marking_a_single_notification_as_read(): void
    {
        $this->notify($this->user);
        $notification = $this->user->notifications()->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('notifications.read', ['notification' => $notification->id]))
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($this->user)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('notifications.unread_count', 0));
    }

    public function test_marking_all_notifications_as_read(): void
    {
        $this->notify($this->user);
        $this->notify($this->user);
        $this->notify($this->user);

        $this->actingAs($this->user)
            ->post(route('notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $this->user->unreadNotifications()->count());

        $this->actingAs($this->user)
            ->get(route('dashboard', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('notifications.unread_count', 0));
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $otherUser = User::factory()->create();
        $this->notify($otherUser);
        $notification = $otherUser->notifications()->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('notifications.read', ['notification' => $notification->id]))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_guests_cannot_manage_notifications(): void
    {
        $this->notify($this->user);
        $notification = $this->user->notifications()->firstOrFail();

        $this->post(route('notifications.read', ['notification' => $notification->id]))->assertRedirect(route('login'));
        $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
    }
}
