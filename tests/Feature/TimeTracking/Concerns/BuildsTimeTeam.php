<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking\Concerns;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The RocketFlood CSR team: the owner (Ali, linked to his Team profile), a
 * member who signs in as Muhammad Usman Ghani, and four CSRs without logins.
 */
trait BuildsTimeTeam
{
    protected User $owner;

    protected User $member;

    protected Workspace $workspace;

    protected Project $project;

    protected Task $task;

    /** @var array<string, Person> */
    protected array $team = [];

    protected function setUpTimeTeam(string $timezone = 'UTC'): void
    {
        $this->owner = User::factory()->create(['name' => 'Ali Hassan']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['timezone' => $timezone]);
        $this->member = User::factory()->create(['name' => 'Muhammad Usman Ghani']);
        $this->workspace->users()->attach($this->member->id, ['role' => UserRole::MEMBER->value]);

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'RocketFlood CSR']);
        $this->project->members()->attach($this->member->id, ['role' => ProjectRole::MEMBER->value]);
        $this->task = Task::factory()->forProject($this->project)->create(['title' => 'Flood Quote Workflow']);

        foreach (['Ali Hassan' => $this->owner, 'Muhammad Usman Ghani' => $this->member, 'Abdul Wahab' => null, 'Faheem' => null, 'Abdul Rehman' => null, 'Muhammad Faizan' => null] as $name => $user) {
            $person = (new Person)->forceFill(['workspace_id' => $this->workspace->id, 'name' => $name, 'title' => 'CSR', 'user_id' => $user?->id]);
            $person->save();
            $this->team[$name] = $person;
        }
    }

    protected function key(): string
    {
        return (string) Str::ulid();
    }

    protected function usman(): Person
    {
        return $this->team['Muhammad Usman Ghani'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function timer(string $action, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->member)
            ->postJson(route("workspace.time.timer.{$action}", $this->workspace), ['operation_key' => $this->key(), ...$data]);
    }

    protected function startTimer(array $data = [], ?User $as = null): TestResponse
    {
        return $this->timer('start', ['project' => $this->project->public_id, ...$data], $as);
    }
}
