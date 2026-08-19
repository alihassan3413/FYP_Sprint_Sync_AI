<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirming an action must carry it out once and then stop.
 *
 * The bug this covers: after a confirmation the assistant gets another turn to
 * report the outcome, and a model that re-proposes the same call turned that
 * into an endless loop of confirmation cards, each one executing again.
 */
final class AssistantConfirmationLoopTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    private BoardColumn $doneColumn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Testing Project']);
        $this->doneColumn = $this->project->boardColumns()->where('is_done', true)->firstOrFail();
    }

    /**
     * A provider that keeps proposing the same tool call, exactly as the real one
     * did when it saw its own unfulfilled request still standing.
     *
     * @param  array<string, mixed>  $args
     */
    private function fakeProviderRepeating(string $tool, array $args): void
    {
        $this->app->bind(AiProvider::class, fn () => new class($tool, $args) implements AiProvider
        {
            public int $calls = 0;

            /**
             * @param  array<string, mixed>  $args
             */
            public function __construct(private string $tool, private array $args) {}

            public function streamChat(array $messages, array $tools, string $model, float $temperature = 0.7): Generator
            {
                $this->calls++;

                yield [
                    'type' => 'tool_call',
                    'id' => 'call_'.$this->calls,
                    'name' => $this->tool,
                    'args' => $this->args,
                ];

                yield ['type' => 'usage', 'input_tokens' => 1, 'output_tokens' => 1];
            }

            public function name(): string
            {
                return 'fake';
            }
        });
    }

    /**
     * Confirms a pending action and drains the event stream, returning what the
     * client would have received.
     */
    private function confirm(Message $pending): string
    {
        $response = $this->actingAs($this->owner)->post(route('assistant.confirm'), [
            'message_id' => $pending->id,
            'action' => 'confirm',
        ]);

        $response->assertOk();

        return $response->streamedContent();
    }

    private function task(): Task
    {
        return Task::factory()->create([
            'title' => 'Testing UI UX',
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $this->project->boardColumns()->where('position', 0)->value('id'),
        ]);
    }

    private function conversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->owner->id,
            'workspace_id' => $this->workspace->id,
        ]);
    }

    public function test_confirming_an_action_does_not_ask_again_for_the_same_thing(): void
    {
        $task = $this->task();
        $conversation = $this->conversation();

        $args = ['task_id' => $task->id, 'column' => 'done'];
        $this->fakeProviderRepeating('update_task', $args);

        $pending = Message::factory()
            ->pendingTool('update_task', $args)
            ->create(['conversation_id' => $conversation->id]);

        $this->confirm($pending);

        $this->assertSame(Message::STATUS_EXECUTED, $pending->refresh()->tool_status);

        /* The follow-up round must not leave another card waiting for the user. */
        $stillPending = $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->count();

        $this->assertSame(0, $stillPending, 'A repeated tool call left a second confirmation card pending.');
    }

    public function test_the_repeated_call_is_reported_back_as_already_done(): void
    {
        $task = $this->task();
        $conversation = $this->conversation();

        $args = ['task_id' => $task->id, 'column' => 'done'];
        $this->fakeProviderRepeating('update_task', $args);

        $pending = Message::factory()
            ->pendingTool('update_task', $args)
            ->create(['conversation_id' => $conversation->id]);

        $this->confirm($pending);

        $skipped = $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_FAILED)
            ->get()
            ->first(fn (Message $message) => str_contains((string) $message->content, 'already_done'));

        $this->assertNotNull($skipped, 'The duplicate call should be recorded as already_done for the model to read.');
    }

    public function test_the_confirmation_turn_is_not_treated_as_a_new_instruction(): void
    {
        $task = $this->task();
        $conversation = $this->conversation();

        $args = ['task_id' => $task->id, 'column' => 'done'];
        $this->fakeProviderRepeating('update_task', $args);

        $pending = Message::factory()
            ->pendingTool('update_task', $args)
            ->create(['conversation_id' => $conversation->id]);

        $this->confirm($pending);

        $synthetic = $conversation->messages()->where('role', 'user')->latest('id')->first();

        $this->assertTrue($synthetic->metadata['synthetic'] ?? false);
    }

    public function test_the_action_itself_runs_exactly_once(): void
    {
        $task = $this->task();
        $conversation = $this->conversation();

        $args = ['task_id' => $task->id, 'column' => 'done'];
        $this->fakeProviderRepeating('update_task', $args);

        $pending = Message::factory()
            ->pendingTool('update_task', $args)
            ->create(['conversation_id' => $conversation->id]);

        $this->confirm($pending);

        $executed = $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_EXECUTED)
            ->count();

        $this->assertSame(1, $executed);
        $this->assertSame($this->doneColumn->id, $task->fresh()->board_column_id);
    }

    public function test_a_later_turn_can_legitimately_repeat_the_same_action(): void
    {
        $task = $this->task();
        $conversation = $this->conversation();

        $args = ['task_id' => $task->id, 'column' => 'done'];

        /* First turn: executed and recorded. */
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'tool',
            'tool_call_id' => 'call_old',
            'tool_status' => Message::STATUS_EXECUTED,
            'content' => json_encode(['success' => true]),
            'metadata' => ['name' => 'update_task', 'args' => $args],
        ]);

        /* The user speaks again, which opens a new turn. */
        $this->fakeProviderRepeating('update_task', $args);

        $pending = Message::factory()
            ->pendingTool('update_task', $args)
            ->create(['conversation_id' => $conversation->id]);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'do it again please',
        ]);

        $this->confirm($pending);

        $this->assertSame(Message::STATUS_EXECUTED, $pending->refresh()->tool_status);
    }
}
