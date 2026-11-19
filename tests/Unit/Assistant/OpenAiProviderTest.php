<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Drivers\OpenAiProvider;
use App\Modules\Assistant\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiProviderTest extends TestCase
{
    public function test_it_reports_a_rejected_api_key_as_a_provider_exception(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

        $this->expectException(AiProviderException::class);

        iterator_to_array((new OpenAiProvider(apiKey: 'test-key'))->streamChat(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            'gpt-4o-mini',
        ));
    }
}
