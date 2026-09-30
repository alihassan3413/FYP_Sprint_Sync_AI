<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_usable_workspace_for_the_account(): void
    {
        $this->artisan('demo:seed', ['--email' => 'demo@example.test', '--workspaces' => 1, '--projects' => 2])
            ->assertSuccessful();

        $user = User::firstWhere('email', 'demo@example.test');

        $this->assertNotNull($user);

        $workspace = Workspace::where('owner_id', $user->id)->sole();

        $this->assertSame($workspace->id, $user->current_workspace_id);
        $this->assertSame(2, $workspace->projects()->count());
        $this->assertGreaterThan(50, Task::where('workspace_id', $workspace->id)->count());

        // Analytics read completed_at, not the column, so an unset one here
        // is what "no data in the charts" actually looks like.
        $this->assertGreaterThan(
            0,
            Task::where('workspace_id', $workspace->id)->whereNotNull('completed_at')->count(),
        );
    }

    public function test_fresh_replaces_the_previous_run(): void
    {
        $args = ['--email' => 'demo@example.test', '--workspaces' => 1, '--projects' => 1];

        $this->artisan('demo:seed', $args)->assertSuccessful();
        $this->artisan('demo:seed', $args + ['--fresh' => true])->assertSuccessful();

        $user = User::firstWhere('email', 'demo@example.test');

        $this->assertSame(1, Workspace::where('owner_id', $user->id)->count());
    }
}
