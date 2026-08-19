<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Actions\BuildAssistantUsageReport;
use App\Modules\Admin\Actions\BuildPlatformMetrics;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_platform_metrics_count_every_workspace_not_just_the_admins(): void
    {
        $admin = $this->superAdmin();

        // Two workspaces owned by unrelated people. The admin belongs to
        // neither, and must still see both.
        $first = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $second = Workspace::factory()->ownedBy(User::factory()->create())->create();

        Project::factory()->for($first)->create();
        Project::factory()->for($second)->count(2)->create();

        $metrics = app(BuildPlatformMetrics::class)->handle();

        $this->assertSame(2, $metrics->workspaces_total);
        $this->assertSame(3, $metrics->projects_total);
        $this->assertSame(3, $metrics->users_total, 'Two owners plus the admin.');

        $this->assertFalse($first->hasMember($admin));
        $this->assertFalse($second->hasMember($admin));
    }

    public function test_the_signup_series_covers_every_day_in_the_window(): void
    {
        $metrics = app(BuildPlatformMetrics::class)->handle();

        // 30 days back plus today, inclusive.
        $this->assertCount(31, $metrics->signups);
        $this->assertSame(now()->subDays(30)->toDateString(), $metrics->signups[0]->date);
        $this->assertSame(now()->toDateString(), $metrics->signups[30]->date);
    }

    public function test_verified_users_are_counted_separately(): void
    {
        User::factory()->count(2)->create();
        User::factory()->unverified()->create();

        $metrics = app(BuildPlatformMetrics::class)->handle();

        $this->assertSame(3, $metrics->users_total);
        $this->assertSame(2, $metrics->users_verified);
    }

    public function test_assistant_cost_is_priced_per_model_from_config(): void
    {
        config(['assistant.pricing' => [
            'default' => ['input' => 0.0, 'output' => 0.0],
            'claude-sonnet-5' => ['input' => 300.0, 'output' => 1500.0],
        ]]);

        $conversation = Conversation::factory()->create(['user_id' => User::factory()->create()->id]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'provider' => 'anthropic',
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
        ]);

        $usage = app(BuildAssistantUsageReport::class)->handle();

        // 300 cents in + 1500 cents out.
        $this->assertSame(1800, $usage->estimated_cost_cents);
        $this->assertSame(1_000_000, $usage->input_tokens);
        $this->assertSame(1_000_000, $usage->output_tokens);

        $this->assertCount(1, $usage->by_model);
        $this->assertSame('claude-sonnet-5', $usage->by_model[0]->model);
        $this->assertSame('anthropic', $usage->by_model[0]->provider);
        $this->assertSame(1800, $usage->by_model[0]->estimated_cost_cents);
    }

    public function test_a_user_spanning_two_models_is_priced_at_each_models_own_rate(): void
    {
        config(['assistant.pricing' => [
            'default' => ['input' => 0.0, 'output' => 0.0],
            'expensive' => ['input' => 1000.0, 'output' => 1000.0],
            'cheap' => ['input' => 10.0, 'output' => 10.0],
        ]]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        foreach (['expensive' => 1_000_000, 'cheap' => 1_000_000] as $model => $tokens) {
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'model' => $model,
                'input_tokens' => $tokens,
                'output_tokens' => 0,
            ]);
        }

        $usage = app(BuildAssistantUsageReport::class)->handle();

        $this->assertCount(1, $usage->top_users, 'The two model rows fold into one user.');
        $this->assertSame($user->id, $usage->top_users[0]->id);
        $this->assertSame(2, $usage->top_users[0]->messages);
        // Folding to a single rate would give either 2000 or 20, not 1010.
        $this->assertSame(1010, $usage->top_users[0]->estimated_cost_cents);
    }

    public function test_the_dashboard_lists_users_and_workspaces_from_every_tenant(): void
    {
        $admin = $this->superAdmin();
        $stranger = User::factory()->create(['name' => 'Unrelated Person']);
        Workspace::factory()->ownedBy($stranger)->create(['name' => 'Someone Elses Workspace']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/index')
                ->has('metrics')
                ->has('assistantUsage')
                ->has('users.data', 2)
                ->has('workspaces.data', 1)
                ->where('workspaces.data.0.name', 'Someone Elses Workspace')
                ->where('workspaces.data.0.owner_name', 'Unrelated Person')
            );
    }

    public function test_the_user_directory_can_be_searched(): void
    {
        $admin = $this->superAdmin();
        User::factory()->create(['name' => 'Findable Person', 'email' => 'findable@example.com']);
        User::factory()->create(['name' => 'Other Person', 'email' => 'other@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['user_search' => 'findable']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.email', 'findable@example.com')
            );
    }

    public function test_the_workspace_directory_can_be_searched(): void
    {
        $admin = $this->superAdmin();
        Workspace::factory()->ownedBy(User::factory()->create())->create(['name' => 'Alpha Team', 'slug' => 'alpha-team']);
        Workspace::factory()->ownedBy(User::factory()->create())->create(['name' => 'Beta Team', 'slug' => 'beta-team']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['workspace_search' => 'alpha']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('workspaces.data', 1)
                ->where('workspaces.data.0.slug', 'alpha-team')
            );
    }

    public function test_the_two_directories_paginate_independently(): void
    {
        $admin = $this->superAdmin();
        User::factory()->count(25)->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['users_page' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users.current_page', 2)
                ->where('workspaces.current_page', 1)
            );
    }
}
