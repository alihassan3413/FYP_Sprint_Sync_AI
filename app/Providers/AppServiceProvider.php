<?php

namespace App\Providers;

use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Providers\OpenAiProvider;
use App\Modules\Assistant\Tools\CreateWorkspaceTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the AI provider — swap providers via config.
        $this->app->bind(AiProvider::class, function () {
            $driver = config('assistant.driver', 'openai');

            return match ($driver) {
                'openai' => new OpenAiProvider(
                    apiKey: config('assistant.openai.api_key'),
                    baseUrl: config('assistant.openai.base_url', 'https://api.openai.com/v1'),
                ),
                default => throw new \RuntimeException("Unknown AI driver: {$driver}"),
            };
        });

        $this->app->singleton(ToolRegistry::class);
    }

    public function boot(): void
    {
        $this->registerAssistantTools();
        $this->registerAssistantRateLimits();
    }

    private function registerAssistantTools(): void
    {
        $registry = $this->app->make(ToolRegistry::class);
        $registry->register($this->app->make(CreateWorkspaceTool::class));
        // Add more tools here as you build them.
    }

    private function registerAssistantRateLimits(): void
    {
        RateLimiter::for('assistant-chat', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()),
                Limit::perDay(200)->by($request->user()?->id ?: $request->ip()),
            ];
        });
    }
}
