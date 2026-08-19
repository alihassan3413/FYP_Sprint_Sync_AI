<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Drivers\AnthropicProvider;
use App\Modules\Assistant\Exceptions\AiProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnthropicProviderTest extends TestCase
{
    private function provider(): AnthropicProvider
    {
        return new AnthropicProvider(apiKey: 'test-key', baseUrl: 'https://api.anthropic.com/v1');
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function fakeStream(array $events): void
    {
        $body = '';

        foreach ($events as $event) {
            $body .= 'event: '.$event['type']."\n".'data: '.json_encode($event)."\n\n";
        }

        Http::fake([
            'api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    private function collect(array $messages, array $tools = []): array
    {
        return iterator_to_array(
            $this->provider()->streamChat($messages, $tools, 'claude-sonnet-5'),
            false,
        );
    }

    private function sentPayload(): array
    {
        $payload = [];

        Http::assertSent(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return true;
        });

        return $payload;
    }

    public function test_it_streams_text_deltas_as_normalized_events(): void
    {
        $this->fakeStream([
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 120]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'there.']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 8]],
            ['type' => 'message_stop'],
        ]);

        $events = $this->collect([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame(
            [
                ['type' => 'text', 'delta' => 'Hello '],
                ['type' => 'text', 'delta' => 'there.'],
                ['type' => 'usage', 'input_tokens' => 120, 'output_tokens' => 8],
                ['type' => 'finish', 'reason' => 'stop'],
            ],
            $events,
        );
    }

    public function test_it_reassembles_tool_call_arguments_split_across_deltas(): void
    {
        $this->fakeStream([
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 50]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
                'type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'create_project', 'input' => [],
            ]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"name":']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"Apollo"}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 12]],
        ]);

        $events = $this->collect([['role' => 'user', 'content' => 'Make a project']]);

        $this->assertContains(
            ['type' => 'tool_call', 'id' => 'toolu_1', 'name' => 'create_project', 'args' => ['name' => 'Apollo']],
            $events,
        );
        $this->assertContains(['type' => 'finish', 'reason' => 'tool_calls'], $events);
    }

    public function test_it_hoists_the_system_message_out_of_the_message_list(): void
    {
        $this->fakeStream([['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);

        $this->collect([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $payload = $this->sentPayload();

        $this->assertSame('You are helpful.', $payload['system']);
        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
        ], $payload['messages']);
    }

    public function test_it_converts_openai_tool_calls_and_results_into_claude_content_blocks(): void
    {
        $this->fakeStream([['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);

        $this->collect([
            ['role' => 'user', 'content' => 'List my projects'],
            ['role' => 'assistant', 'tool_calls' => [[
                'id' => 'toolu_1',
                'type' => 'function',
                'function' => ['name' => 'list_projects', 'arguments' => '{"limit":5}'],
            ]]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"projects":[]}'],
        ]);

        $messages = $this->sentPayload()['messages'];

        $this->assertSame([
            'type' => 'tool_use',
            'id' => 'toolu_1',
            'name' => 'list_projects',
            'input' => ['limit' => 5],
        ], $messages[1]['content'][0]);

        $this->assertSame('user', $messages[2]['role']);
        $this->assertSame([
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_1',
            'content' => '{"projects":[]}',
        ], $messages[2]['content'][0]);
    }

    public function test_it_folds_parallel_tool_results_into_a_single_user_turn(): void
    {
        $this->fakeStream([['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);

        $this->collect([
            ['role' => 'user', 'content' => 'Do both'],
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'a', 'arguments' => '{}']],
                ['id' => 'toolu_2', 'type' => 'function', 'function' => ['name' => 'b', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => 'one'],
            ['role' => 'tool', 'tool_call_id' => 'toolu_2', 'content' => 'two'],
        ]);

        $messages = $this->sentPayload()['messages'];

        $this->assertCount(3, $messages, 'Both tool results belong to one user turn.');
        $this->assertCount(2, $messages[2]['content']);
        $this->assertSame('toolu_1', $messages[2]['content'][0]['tool_use_id']);
        $this->assertSame('toolu_2', $messages[2]['content'][1]['tool_use_id']);
    }

    public function test_a_tool_awaiting_confirmation_still_answers_its_tool_use_block(): void
    {
        $this->fakeStream([['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);

        $this->collect([
            ['role' => 'user', 'content' => 'Invite someone'],
            ['role' => 'assistant', 'tool_calls' => [[
                'id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'invite_user', 'arguments' => '{}'],
            ]]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1'],
        ]);

        $messages = $this->sentPayload()['messages'];

        $this->assertNotEmpty($messages[2]['content'][0]['content']);
    }

    public function test_it_translates_tool_schemas_and_omits_sampling_parameters(): void
    {
        $this->fakeStream([['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);

        $parameters = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ];

        $this->collect(
            [['role' => 'user', 'content' => 'Hi']],
            [['type' => 'function', 'function' => [
                'name' => 'create_project',
                'description' => 'Create a project',
                'parameters' => $parameters,
            ]]],
        );

        $payload = $this->sentPayload();

        $this->assertSame([[
            'name' => 'create_project',
            'description' => 'Create a project',
            'input_schema' => $parameters,
        ]], $payload['tools']);

        // Claude Sonnet 5 rejects these outright.
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertArrayNotHasKey('top_p', $payload);
        $this->assertArrayNotHasKey('top_k', $payload);
    }

    public function test_an_argumentless_tool_call_replays_its_input_as_an_object(): void
    {
        $this->fakeStream([['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);

        $this->collect([
            ['role' => 'user', 'content' => 'What projects do I have?'],
            ['role' => 'assistant', 'tool_calls' => [[
                'id' => 'toolu_1',
                'type' => 'function',
                'function' => ['name' => 'list_projects', 'arguments' => '{}'],
            ]]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"projects":[]}'],
        ]);

        $body = '';

        Http::assertSent(function (Request $request) use (&$body) {
            $body = $request->body();

            return true;
        });

        // "input":[] makes Claude reject the whole conversation with a 400.
        $this->assertStringContainsString('"input":{}', $body);
        $this->assertStringNotContainsString('"input":[]', $body);
    }

    public function test_an_argumentless_tool_encodes_its_properties_as_an_object(): void
    {
        $this->fakeStream([['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);

        $this->collect(
            [['role' => 'user', 'content' => 'Hi']],
            [['type' => 'function', 'function' => [
                'name' => 'list_workspaces',
                'description' => 'List workspaces',
                'parameters' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ]]],
        );

        $body = '';

        Http::assertSent(function (Request $request) use (&$body) {
            $body = $request->body();

            return true;
        });

        // Claude rejects the request outright if this encodes as [].
        $this->assertStringContainsString('"properties":{}', $body);
    }

    public function test_it_reports_a_failed_request_as_a_provider_exception(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $this->expectException(AiProviderException::class);

        $this->collect([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_it_surfaces_a_mid_stream_error_event(): void
    {
        $this->fakeStream([
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]],
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
        ]);

        $this->expectException(AiProviderException::class);

        $this->collect([['role' => 'user', 'content' => 'Hi']]);
    }
}
