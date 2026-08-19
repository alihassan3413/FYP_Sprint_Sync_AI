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
 * The whole path a real request travels: user message, tool round, confirmation
 * card, confirmation, and the reply afterwards. Driven by a scripted model so
 * the behaviour under test is ours, not the provider's.
 */
final class AssistantTaskConversationFlowTest extends TestCase
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
     * Scripts the model: one entry per round, each a list of provider events.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rounds
     */
    private function scriptModel(array $rounds): void
    {
        /* A singleton so the script advances across requests instead of restarting. */
        $this->app->singleton(AiProvider::class, fn () => new class($rounds) implements AiProvider
        {
            private int $round = 0;

            /**
             * @param  array<int, array<int, array<string, mixed>>>  $rounds
             */
            public function __construct(private array $rounds) {}

            public function streamChat(array $messages, array $tools, string $model, float $temperature = 0.7): Generator
            {
                $events = $this->rounds[$this->round] ?? [['type' => 'text', 'delta' => 'Done.']];
                $this->round++;

                foreach ($events as $event) {
                    yield $event;
                }

                yield ['type' => 'usage', 'input_tokens' => 1, 'output_tokens' => 1];
            }

            public function name(): string
            {
                return 'scripted';
            }
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $args, string $id = 'call_1'): array
    {
        return ['type' => 'tool_call', 'id' => $id, 'name' => $name, 'args' => $args];
    }

    private function task(string $title = 'Testing UI UX'): Task
    {
        return Task::factory()->create([
            'title' => $title,
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'board_column_id' => $this->project->boardColumns()->where('position', 0)->value('id'),
        ]);
    }

    private function say(string $message, ?Conversation $conversation = null): string
    {
        $response = $this->actingAs($this->owner)->post(route('assistant.chat'), array_filter([
            'message' => $message,
            'conversation_id' => $conversation?->id,
            'workspace_id' => $this->workspace->id,
        ]));

        $response->assertOk();

        return $response->streamedContent();
    }

    private function confirm(Message $pending, string $action = 'confirm'): string
    {
        $response = $this->actingAs($this->owner)->post(route('assistant.confirm'), [
            'message_id' => $pending->id,
            'action' => $action,
        ]);

        $response->assertOk();

        return $response->streamedContent();
    }

    private function latestConversation(): Conversation
    {
        return Conversation::query()->latest('id')->firstOrFail();
    }

    private function pendingMessage(Conversation $conversation): ?Message
    {
        return $conversation->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    public function test_a_read_only_search_runs_without_asking_the_user_anything(): void
    {
        $this->task('UI/UX modification');

        $this->scriptModel([
            [$this->toolCall('find_tasks', ['query' => 'ui ux'])],
            [['type' => 'text', 'delta' => 'I found one task.']],
        ]);

        $stream = $this->say('which UI UX tasks are there?');

        $this->assertStringContainsString('tool_executed', $stream);
        $this->assertStringNotContainsString('tool_pending', $stream);
        $this->assertNull($this->pendingMessage($this->latestConversation()));
    }

    public function test_a_deletion_asks_first_and_only_deletes_once_confirmed(): void
    {
        $task = $this->task();

        $this->scriptModel([
            [$this->toolCall('delete_task', ['task_id' => $task->id])],
            [['type' => 'text', 'delta' => 'Deleted it.']],
        ]);

        $this->say('delete the testing UI UX task');

        $conversation = $this->latestConversation();
        $pending = $this->pendingMessage($conversation);

        $this->assertNotNull($pending);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);

        $this->confirm($pending);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertNull($this->pendingMessage($conversation->fresh()));
    }

    public function test_cancelling_leaves_the_task_exactly_as_it_was(): void
    {
        $task = $this->task();

        $this->scriptModel([
            [$this->toolCall('delete_task', ['task_id' => $task->id])],
            [['type' => 'text', 'delta' => 'Cancelled.']],
        ]);

        $this->say('delete the testing UI UX task');

        $pending = $this->pendingMessage($this->latestConversation());

        $this->confirm($pending, 'reject');

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertSame(Message::STATUS_REJECTED, $pending->refresh()->tool_status);
        $this->assertNull($this->pendingMessage($this->latestConversation()));
    }

    public function test_the_same_call_twice_in_one_answer_only_counts_once(): void
    {
        $task = $this->task();

        $this->scriptModel([
            [
                $this->toolCall('find_tasks', ['query' => 'ui ux'], 'call_a'),
                $this->toolCall('find_tasks', ['query' => 'ui ux'], 'call_b'),
            ],
            [['type' => 'text', 'delta' => 'Here it is.']],
        ]);

        $stream = $this->say('find the ui ux task');

        $this->assertStringContainsString('tool_skipped', $stream);

        $executed = $this->latestConversation()->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_EXECUTED)
            ->count();

        $this->assertSame(1, $executed);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_two_different_tasks_in_one_answer_both_get_their_own_card(): void
    {
        $first = $this->task('First task');
        $second = $this->task('Second task');

        $this->scriptModel([
            [
                $this->toolCall('update_task', ['task_id' => $first->id, 'column' => 'done'], 'call_a'),
                $this->toolCall('update_task', ['task_id' => $second->id, 'column' => 'done'], 'call_b'),
            ],
        ]);

        $this->say('mark both tasks done');

        $pendingCount = $this->latestConversation()->messages()
            ->where('role', 'tool')
            ->where('tool_status', Message::STATUS_PENDING)
            ->count();

        $this->assertSame(2, $pendingCount, 'Distinct actions must not be collapsed by the duplicate guard.');
    }

    public function test_a_search_then_an_action_flows_through_in_one_turn(): void
    {
        $task = $this->task('UI/UX modification');

        $this->scriptModel([
            [$this->toolCall('find_tasks', ['query' => 'ui ux'], 'call_find')],
            [$this->toolCall('update_task', ['task_id' => $task->id, 'column' => 'done'], 'call_update')],
            [['type' => 'text', 'delta' => 'Marked it done.']],
        ]);

        $this->say('mark the ui ux task as done');

        $conversation = $this->latestConversation();
        $pending = $this->pendingMessage($conversation);

        $this->assertNotNull($pending, 'The write step should still ask before acting.');
        $this->assertSame('update_task', $pending->metadata['name']);

        $this->confirm($pending);

        $this->assertSame($this->doneColumn->id, $task->fresh()->board_column_id);
    }

    public function test_a_failing_tool_does_not_leave_the_conversation_stuck(): void
    {
        $this->scriptModel([
            [$this->toolCall('update_task', ['task_id' => 999999, 'column' => 'done'])],
            [['type' => 'text', 'delta' => 'I could not find that task.']],
        ]);

        $this->say('mark task 999999 done');

        $conversation = $this->latestConversation();
        $pending = $this->pendingMessage($conversation);

        $this->assertNotNull($pending);

        $stream = $this->confirm($pending);

        $this->assertStringContainsString('task_not_found', $stream);
        $this->assertSame(Message::STATUS_FAILED, $pending->refresh()->tool_status);
        $this->assertNull($this->pendingMessage($conversation->fresh()));
    }

    public function test_an_unknown_tool_name_is_handled_without_breaking_the_turn(): void
    {
        $this->scriptModel([
            [$this->toolCall('archive_task', ['task_id' => 1])],
            [['type' => 'text', 'delta' => 'I cannot do that.']],
        ]);

        $stream = $this->say('archive the ui ux task');

        $this->assertStringContainsString('tool_failed', $stream);
        $this->assertStringContainsString('stream_end', $stream);
        $this->assertNull($this->pendingMessage($this->latestConversation()));
    }

    public function test_asking_something_new_supersedes_a_card_left_hanging(): void
    {
        $task = $this->task();

        $this->scriptModel([
            [$this->toolCall('delete_task', ['task_id' => $task->id])],
            [['type' => 'text', 'delta' => 'Yes, I can hear you.']],
        ]);

        $this->say('delete the testing UI UX task');

        $conversation = $this->latestConversation();
        $pending = $this->pendingMessage($conversation);

        $this->assertNotNull($pending);

        $this->say('can you hear me?', $conversation);

        $this->assertSame(Message::STATUS_SUPERSEDED, $pending->refresh()->tool_status);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_confirming_an_old_card_after_moving_on_does_not_resurrect_it(): void
    {
        $task = $this->task();

        $this->scriptModel([
            [$this->toolCall('delete_task', ['task_id' => $task->id])],
            [['type' => 'text', 'delta' => 'Sure.']],
            [['type' => 'text', 'delta' => 'Sure.']],
        ]);

        $this->say('delete the testing UI UX task');

        $conversation = $this->latestConversation();
        $pending = $this->pendingMessage($conversation);

        $this->say('never mind, what is the weather', $conversation);

        $this->actingAs($this->owner)
            ->post(route('assistant.confirm'), ['message_id' => $pending->id, 'action' => 'confirm'])
            ->assertNotFound();

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }
}
