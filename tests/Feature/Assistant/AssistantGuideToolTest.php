<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Support\CommandCatalog;
use App\Modules\Assistant\Support\GuideAudience;
use App\Modules\Assistant\Support\GuideLibrary;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Tools\GuideTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Data\ClientPermission;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssistantGuideToolTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
    }

    private function tool(): GuideTool
    {
        return app(GuideTool::class);
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function ask(User $user, array $args = [], ?Workspace $workspace = null): array
    {
        return $this->tool()->execute($args, new ToolContext($user, $workspace ?? $this->workspace));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function topicsIn(array $result): array
    {
        $topics = [];

        foreach ($result['curriculum'] as $stage) {
            foreach ($stage['lessons'] as $lesson) {
                $topics[] = $lesson['topic'];
            }
        }

        return $topics;
    }

    public function test_the_tool_is_registered_with_command_palette_copy(): void
    {
        $this->assertContains('get_guide', CommandCatalog::describedToolNames());
        $this->assertNotNull(app(ToolRegistry::class)->get('get_guide'));
    }

    public function test_it_never_asks_for_confirmation(): void
    {
        $this->assertFalse($this->tool()->requiresConfirmation());
    }

    public function test_any_signed_in_user_may_use_it_even_without_a_workspace(): void
    {
        $stranger = User::factory()->create();

        $this->assertTrue($this->tool()->authorize(new ToolContext($stranger, null)));
    }

    public function test_a_user_with_no_workspace_is_still_given_a_starting_curriculum(): void
    {
        $stranger = User::factory()->create();

        $result = $this->tool()->execute([], new ToolContext($stranger, null));

        $this->assertTrue($result['success']);
        $this->assertSame('orientation', $result['start_here']);
        $this->assertStringContainsString('not in a workspace yet', $result['learner']);
        $this->assertContains('workspaces', $this->topicsIn($result));
    }

    public function test_an_owner_is_taught_the_whole_product(): void
    {
        $result = $this->ask($this->owner);

        $topics = $this->topicsIn($result);

        $this->assertContains('roles-permissions', $topics);
        $this->assertContains('clients', $topics);
        $this->assertContains('sprints', $topics);
        $this->assertContains('invite-team', $topics);
        $this->assertSame(count($topics), $result['total_lessons']);
    }

    public function test_a_plain_member_is_not_taught_administration(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $topics = $this->topicsIn($this->ask($member));

        $this->assertNotContains('roles-permissions', $topics);
        $this->assertNotContains('clients', $topics);
        $this->assertNotContains('invite-team', $topics);
        $this->assertContains('tasks', $topics);
        $this->assertContains('board', $topics);
    }

    public function test_a_member_who_manages_a_project_is_taught_sprints(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->members()->attach($member->id, ['role' => ProjectRole::MANAGER->value]);

        $topics = $this->topicsIn($this->ask($member));

        $this->assertContains('sprints', $topics);
        $this->assertContains('meetings-manage', $topics);
        $this->assertContains('board-columns', $topics);
    }

    public function test_a_plain_member_without_a_project_is_not_taught_sprint_management(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $topics = $this->topicsIn($this->ask($member));

        $this->assertNotContains('sprints', $topics);
        $this->assertNotContains('meetings-manage', $topics);
    }

    public function test_a_client_gets_client_training_and_nothing_else(): void
    {
        $client = $this->memberOf(UserRole::CLIENT);

        $result = $this->ask($client);
        $topics = $this->topicsIn($result);

        $this->assertContains('client-basics', $topics);
        $this->assertStringContainsString('client', $result['learner']);

        foreach (['invite-team', 'projects', 'roles-permissions', 'clients', 'sprints', 'tasks', 'analytics', 'archive-audit'] as $forbidden) {
            $this->assertNotContains($forbidden, $topics, "A client was offered the '{$forbidden}' lesson.");
        }
    }

    public function test_a_client_cannot_reach_an_administration_lesson_by_naming_its_slug(): void
    {
        $client = $this->memberOf(UserRole::CLIENT);

        $result = $this->ask($client, ['topic' => 'roles-permissions']);

        $this->assertNotSame('roles-permissions', $result['lesson']['topic'] ?? null);
    }

    public function test_a_client_asking_about_inviting_people_is_not_taught_how(): void
    {
        $client = $this->memberOf(UserRole::CLIENT);

        $result = $this->ask($client, ['topic' => 'how do I invite someone to the workspace']);

        $this->assertNotSame('invite-team', $result['lesson']['topic'] ?? null);
    }

    public function test_a_topic_returns_one_lesson_with_steps_and_its_place_in_the_course(): void
    {
        $result = $this->ask($this->owner, ['topic' => 'how do sprints work']);

        $this->assertTrue($result['found']);
        $this->assertSame('sprints', $result['lesson']['topic']);
        $this->assertNotEmpty($result['lesson']['steps']);
        $this->assertNotEmpty($result['lesson']['try_saying']);
        $this->assertSame(GuideLibrary::STAGE_TEAM, $result['lesson']['stage']);
        $this->assertIsInt($result['lesson']['lesson_number']);
    }

    public function test_natural_phrasing_finds_the_right_lesson(): void
    {
        $cases = [
            'how do I give a customer access' => 'clients',
            'teach me about the board' => 'board',
            'how are we doing metrics' => 'analytics',
            'meeting transcripts' => 'transcripts',
            'talking to the assistant' => 'assistant-basics',
        ];

        foreach ($cases as $phrase => $expected) {
            $result = $this->ask($this->owner, ['topic' => $phrase]);

            $this->assertSame(
                $expected,
                $result['lesson']['topic'] ?? ($result['candidates'][0]['topic'] ?? null),
                "\"{$phrase}\" did not lead to the {$expected} lesson.",
            );
        }
    }

    public function test_an_unknown_topic_falls_back_to_the_curriculum_instead_of_inventing_one(): void
    {
        $result = $this->ask($this->owner, ['topic' => 'export everything to a spreadsheet']);

        $this->assertFalse($result['found']);
        $this->assertArrayHasKey('curriculum', $result);
        $this->assertArrayNotHasKey('lesson', $result);
    }

    public function test_every_lesson_is_reachable_by_somebody(): void
    {
        $staff = new GuideAudience(
            hasWorkspace: true,
            isClient: false,
            managesProjects: true,
            canInvite: true,
            canCreateProjects: true,
            canManageRoles: true,
        );

        $client = new GuideAudience(
            hasWorkspace: true,
            isClient: true,
            managesProjects: false,
            canInvite: false,
            canCreateProjects: false,
            canManageRoles: false,
        );

        $reachable = array_unique([...GuideLibrary::slugsFor($staff), ...GuideLibrary::slugsFor($client)]);

        $this->assertEqualsCanonicalizing(
            GuideLibrary::order(),
            $reachable,
            'A lesson is gated on an audience nobody can satisfy.',
        );
    }

    public function test_every_lesson_has_enough_content_to_teach_with(): void
    {
        $staff = new GuideAudience(
            hasWorkspace: true,
            isClient: false,
            managesProjects: true,
            canInvite: true,
            canCreateProjects: true,
            canManageRoles: true,
        );

        foreach (GuideLibrary::slugsFor($staff) as $slug) {
            $lesson = GuideLibrary::lesson($slug, $staff);

            $this->assertNotEmpty($lesson['title'], "{$slug} has no title.");
            $this->assertNotEmpty($lesson['summary'], "{$slug} has no summary.");
            $this->assertGreaterThanOrEqual(3, count($lesson['steps']), "{$slug} has too few steps to teach anything.");
        }
    }

    public function test_the_last_lesson_has_no_next_topic_and_the_others_all_do(): void
    {
        $audience = GuideAudience::for(new ToolContext($this->owner, $this->workspace));
        $slugs = GuideLibrary::slugsFor($audience);
        $last = end($slugs);

        $this->assertNull(GuideLibrary::lesson($last, $audience)['next_topic']);

        foreach (array_slice($slugs, 0, -1) as $slug) {
            $this->assertNotNull(GuideLibrary::lesson($slug, $audience)['next_topic'], "{$slug} leads nowhere.");
        }
    }

    public function test_a_custom_role_that_only_grants_invites_unlocks_only_the_invite_lesson(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Recruiter',
            'slug' => 'recruiter',
            'permissions' => ['members.invite' => true],
        ]);

        $member = $this->memberOf(UserRole::MEMBER);
        $this->workspace->users()->updateExistingPivot($member->id, ['workspace_role_id' => $role->id]);

        $topics = $this->topicsIn($this->ask($member->refresh()));

        $this->assertContains('invite-team', $topics);
        $this->assertNotContains('roles-permissions', $topics);
        $this->assertNotContains('projects', $topics);
    }

    public function test_client_permissions_do_not_leak_workspace_lessons(): void
    {
        $role = WorkspaceRole::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Full client',
            'slug' => 'full-client',
            'permissions' => array_fill_keys(ClientPermission::values(), true),
        ]);

        $client = $this->memberOf(UserRole::CLIENT);
        $this->workspace->users()->updateExistingPivot($client->id, ['workspace_role_id' => $role->id]);

        $topics = $this->topicsIn($this->ask($client->refresh()));

        $this->assertSame(['orientation', 'assistant-basics', 'workspaces', 'client-basics', 'board', 'meetings', 'notifications'], $topics);
    }
}
