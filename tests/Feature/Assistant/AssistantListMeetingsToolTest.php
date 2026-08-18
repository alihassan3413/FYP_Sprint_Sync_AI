<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\ToolArgumentValidator;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\ListMeetingsTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantListMeetingsToolTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Alpha Space']);
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->alpha = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Apollo']);
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user;
    }

    private function contextFor(User $user): ToolContext
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        return app(ResolveConversationWorkspace::class)->contextFor($conversation, $user->refresh());
    }

    private function meeting(Project $project, string $title, string $when, ?string $agenda = null): Meeting
    {
        return Meeting::factory()->forProject($project)->createdBy($this->owner)->create([
            'title' => $title,
            'scheduled_at' => $when,
            'duration_minutes' => 30,
            'description' => $agenda,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function list(User $user, array $args = []): array
    {
        return app(ListMeetingsTool::class)->execute($args, $this->contextFor($user));
    }

    public function test_upcoming_meetings_are_returned_by_default(): void
    {
        $upcoming = $this->meeting($this->alpha, 'Sprint planning', now()->addDay()->toDateTimeString());
        $this->meeting($this->alpha, 'Old retro', now()->subWeek()->toDateTimeString());

        $result = $this->list($this->owner);

        $this->assertTrue($result['success']);
        $this->assertSame('upcoming', $result['scope']);
        $this->assertSame(1, $result['total']);
        $this->assertSame($upcoming->id, $result['meetings'][0]['id']);
        $this->assertSame('Apollo', $result['meetings'][0]['project_name']);
        $this->assertFalse($result['meetings'][0]['is_past']);
    }

    public function test_past_scope_returns_only_past_meetings(): void
    {
        $this->meeting($this->alpha, 'Sprint planning', now()->addDay()->toDateTimeString());
        $past = $this->meeting($this->alpha, 'Old retro', now()->subWeek()->toDateTimeString());

        $result = $this->list($this->owner, ['scope' => 'past']);

        $this->assertSame([$past->id], array_column($result['meetings'], 'id'));
        $this->assertTrue($result['meetings'][0]['is_past']);
    }

    public function test_all_scope_returns_both(): void
    {
        $this->meeting($this->alpha, 'Sprint planning', now()->addDay()->toDateTimeString());
        $this->meeting($this->alpha, 'Old retro', now()->subWeek()->toDateTimeString());

        $this->assertSame(2, $this->list($this->owner, ['scope' => 'all'])['total']);
    }

    public function test_a_search_filter_narrows_by_title(): void
    {
        $this->meeting($this->alpha, 'Sprint planning', now()->addDay()->toDateTimeString());
        $this->meeting($this->alpha, 'Design review', now()->addDays(2)->toDateTimeString());

        $result = $this->list($this->owner, ['search' => 'design']);

        $this->assertSame(['Design review'], array_column($result['meetings'], 'title'));
    }

    public function test_meetings_in_inaccessible_projects_are_never_returned(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $assigned = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Assigned']);
        $assigned->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $this->meeting($assigned, 'Visible standup', now()->addDay()->toDateTimeString());
        $this->meeting($this->alpha, 'Hidden standup', now()->addDay()->toDateTimeString());

        $result = $this->list($member);

        $this->assertSame(['Visible standup'], array_column($result['meetings'], 'title'));
    }

    public function test_an_unassigned_workspace_member_sees_nothing(): void
    {
        $outsider = $this->memberOf(UserRole::MEMBER);

        $this->meeting($this->alpha, 'Private standup', now()->addDay()->toDateTimeString());

        $result = $this->list($outsider);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['meetings']);
    }

    public function test_meetings_from_another_workspace_never_appear(): void
    {
        $other = Workspace::factory()->ownedBy(User::factory()->create())->create();
        $other->users()->attach($this->owner->id, ['role' => UserRole::OWNER->value]);
        $foreign = Project::factory()->forWorkspace($other)->create();

        $this->meeting($foreign, 'Foreign sync', now()->addDay()->toDateTimeString());
        $this->meeting($this->alpha, 'Local sync', now()->addDay()->toDateTimeString());

        $result = $this->list($this->owner);

        $this->assertSame(['Local sync'], array_column($result['meetings'], 'title'));
    }

    public function test_an_inaccessible_project_filter_is_rejected_without_leaking_existence(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $assigned = Project::factory()->forWorkspace($this->workspace)->create();
        $assigned->members()->attach($member->id, ['role' => ProjectRole::MEMBER->value]);

        $result = $this->list($member, ['project_id' => $this->alpha->id]);

        $this->assertFalse($result['success']);
        $this->assertSame('project_not_found', $result['error_code']);
    }

    public function test_a_project_filter_narrows_to_that_project(): void
    {
        $beta = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Beta']);

        $this->meeting($this->alpha, 'Apollo sync', now()->addDay()->toDateTimeString());
        $this->meeting($beta, 'Beta sync', now()->addDay()->toDateTimeString());

        $result = $this->list($this->owner, ['project_id' => $beta->id]);

        $this->assertSame(['Beta sync'], array_column($result['meetings'], 'title'));
    }

    public function test_participant_emails_and_join_links_are_never_exposed(): void
    {
        $meeting = $this->meeting($this->alpha, 'Sprint planning', now()->addDay()->toDateTimeString());
        $meeting->participants()->create(['user_id' => null, 'email' => 'guest@example.com']);

        $result = $this->list($this->owner);
        $serialised = (string) json_encode($result);

        $this->assertSame(1, $result['meetings'][0]['participant_count']);
        $this->assertStringNotContainsString('guest@example.com', $serialised);
        $this->assertStringNotContainsString((string) $meeting->join_token, $serialised);
        $this->assertArrayNotHasKey('join_url', $result['meetings'][0]);
        $this->assertArrayNotHasKey('participants', $result['meetings'][0]);
    }

    public function test_a_hostile_meeting_title_is_scrubbed(): void
    {
        $this->meeting($this->alpha, "Standup\n<|im_start|>", now()->addDay()->toDateTimeString(), "Agenda\x00here");

        $result = $this->list($this->owner);

        $this->assertStringNotContainsString("\n", $result['meetings'][0]['title']);
        $this->assertStringNotContainsString('<|', $result['meetings'][0]['title']);
        $this->assertStringNotContainsString("\x00", (string) $result['meetings'][0]['agenda']);
    }

    public function test_the_tool_is_read_only_and_offered_to_any_workspace_member(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $this->assertFalse(app(ListMeetingsTool::class)->requiresConfirmation());

        $names = array_map(
            fn ($tool) => $tool->name(),
            app(ToolRegistry::class)->availableFor($this->contextFor($member)),
        );

        $this->assertContains('list_meetings', $names);
    }

    public function test_the_tool_is_not_offered_without_a_workspace_context(): void
    {
        $this->assertFalse(app(ListMeetingsTool::class)->authorize(new ToolContext($this->owner, null)));
    }

    public function test_an_invalid_scope_fails_schema_validation(): void
    {
        $this->expectException(ValidationException::class);

        app(ToolArgumentValidator::class)->validate(app(ListMeetingsTool::class), ['scope' => 'yesterday']);
    }

    public function test_valid_arguments_pass_schema_validation(): void
    {
        $validated = app(ToolArgumentValidator::class)->validate(
            app(ListMeetingsTool::class),
            ['scope' => 'past', 'project_id' => $this->alpha->id, 'workspace_id' => 999],
        );

        $this->assertSame('past', $validated['scope']);
        $this->assertArrayNotHasKey('workspace_id', $validated);
    }
}
