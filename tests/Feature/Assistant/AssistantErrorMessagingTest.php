<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Actions\ExecuteToolCall;
use App\Modules\Assistant\Actions\ResolveConversationWorkspace;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\ToolFailure;
use App\Modules\Assistant\Tools\CreateProjectTool;
use App\Modules\Assistant\Tools\ListProjectsTool;
use App\Modules\Assistant\Tools\ScheduleMeetingTool;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AssistantErrorMessagingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['name' => 'Acme']);
    }

    private function contextFor(User $user, ?Workspace $workspace = null): ToolContext
    {
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace?->id,
        ]);

        return app(ResolveConversationWorkspace::class)->contextFor($conversation, $user->refresh());
    }

    private function memberOf(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    public function test_a_permission_failure_says_what_is_needed_and_where(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $result = app(ExecuteToolCall::class)->handle(
            app(ScheduleMeetingTool::class),
            [],
            $this->contextFor($member, $this->workspace),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertStringContainsString('schedule meetings', $result['error']);
        $this->assertStringContainsString('Acme', $result['error']);
        $this->assertStringContainsString('workspace admin', $result['error']);
        $this->assertStringNotContainsString('Something went wrong', $result['error']);
    }

    public function test_each_tool_names_its_own_action_in_the_denial(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);
        $context = $this->contextFor($member, $this->workspace);

        $meeting = app(ExecuteToolCall::class)->handle(app(ScheduleMeetingTool::class), [], $context);
        $project = app(ExecuteToolCall::class)->handle(app(CreateProjectTool::class), [], $context);

        $this->assertStringContainsString('schedule meetings', $meeting['error']);
        $this->assertStringContainsString('create a project', $project['error']);
        $this->assertNotSame($meeting['error'], $project['error']);
    }

    public function test_a_missing_workspace_is_explained_rather_than_called_a_permission_problem(): void
    {
        $result = app(ExecuteToolCall::class)->handle(
            app(ListProjectsTool::class),
            [],
            $this->contextFor($this->owner, null),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('unauthorized', $result['error_code']);
        $this->assertStringContainsString('No workspace is selected', $result['error']);
    }

    public function test_an_unknown_tool_is_explained_as_our_problem(): void
    {
        $failure = ToolFailure::unknownTool('delete_everything');

        $this->assertSame('unknown_tool', $failure['error_code']);
        $this->assertStringContainsString('delete_everything', $failure['error']);
        $this->assertStringContainsString('not your', str_replace('not yours', 'not your', $failure['error']));
    }

    public function test_invalid_arguments_report_the_actual_validation_reasons(): void
    {
        $exception = null;

        try {
            Validator::make(['title' => ''], ['title' => ['required']], [
                'title.required' => 'A meeting title is required.',
            ])->validate();
        } catch (ValidationException $e) {
            $exception = $e;
        }

        $failure = ToolFailure::invalidArguments($exception);

        $this->assertSame('invalid_arguments', $failure['error_code']);
        $this->assertStringContainsString('A meeting title is required.', $failure['error']);
    }

    public function test_an_execution_crash_states_that_nothing_changed(): void
    {
        $failure = ToolFailure::executionFailed(app(ScheduleMeetingTool::class));

        $this->assertSame('execution_failed', $failure['error_code']);
        $this->assertStringContainsString('Nothing was changed', $failure['error']);
        $this->assertStringContainsString('schedule meetings', $failure['error']);
    }

    public function test_every_failure_carries_a_code_and_a_non_generic_message(): void
    {
        $member = $this->memberOf(UserRole::MEMBER);

        $failures = [
            ToolFailure::unknownTool('nope'),
            ToolFailure::unauthorized(app(ScheduleMeetingTool::class), $this->contextFor($member, $this->workspace)),
            ToolFailure::executionFailed(app(ScheduleMeetingTool::class)),
        ];

        foreach ($failures as $failure) {
            $this->assertFalse($failure['success']);
            $this->assertNotEmpty($failure['error_code']);
            $this->assertGreaterThan(40, mb_strlen($failure['error']));
            $this->assertStringNotContainsString('Something went wrong', $failure['error']);
        }
    }
}
