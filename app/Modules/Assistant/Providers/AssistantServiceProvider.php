<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Providers;

use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Drivers\OpenAiProvider;
use App\Modules\Assistant\Exceptions\AiProviderException;
use App\Modules\Assistant\Tools\CreateProjectTool;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Assistant\Tools\CreateWorkspaceTool;
use App\Modules\Assistant\Tools\GetWorkspaceInfoTool;
use App\Modules\Assistant\Tools\InvitationTool;
use App\Modules\Assistant\Tools\ListMeetingsTool;
use App\Modules\Assistant\Tools\ListProjectsTool;
use App\Modules\Assistant\Tools\ScheduleMeetingTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AssistantServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Assistant';

    /**
     * @var array<int, class-string>
     */
    private const TOOLS = [
        CreateWorkspaceTool::class,
        InvitationTool::class,
        GetWorkspaceInfoTool::class,
        ListProjectsTool::class,
        ListMeetingsTool::class,
        CreateProjectTool::class,
        CreateTaskTool::class,
        ScheduleMeetingTool::class,
    ];

    public function register(): void
    {
        $this->app->bind(AiProvider::class, fn () => match (config('assistant.driver')) {
            'openai' => new OpenAiProvider(
                apiKey: (string) config('assistant.openai.api_key'),
                baseUrl: (string) config('assistant.openai.base_url'),
            ),
            default => throw AiProviderException::unknownDriver((string) config('assistant.driver')),
        });

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry;

            foreach (self::TOOLS as $tool) {
                $registry->register($app->make($tool));
            }

            return $registry;
        });
    }

    protected function bootModule(): void
    {
        RateLimiter::for('assistant-chat', fn (Request $request) => [
            Limit::perMinute((int) config('assistant.rate_limits.per_minute'))
                ->by($request->user()?->id ?: $request->ip()),
            Limit::perDay((int) config('assistant.rate_limits.per_day'))
                ->by($request->user()?->id ?: $request->ip()),
        ]);
    }
}
