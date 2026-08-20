<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use Tests\TestCase;

final class AssistantModelSelectionTest extends TestCase
{
    public function test_the_assistant_runs_on_claude(): void
    {
        $this->assertSame('anthropic', config('assistant.driver'));
        $this->assertStringStartsWith('claude-', (string) config('assistant.default_model'));
    }

    public function test_only_claude_models_may_be_requested(): void
    {
        /*
         * The driver is fixed, so a model from another vendor would be posted to
         * Anthropic and rejected. The allowlist has to agree with the driver.
         */
        foreach ((array) config('assistant.allowed_models') as $model) {
            $this->assertStringStartsWith('claude-', (string) $model, "{$model} is not a Claude model.");
        }
    }

    public function test_the_default_model_is_one_a_client_may_also_request(): void
    {
        $this->assertContains(
            config('assistant.default_model'),
            (array) config('assistant.allowed_models'),
        );
    }
}
