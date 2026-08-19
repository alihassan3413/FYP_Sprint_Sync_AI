<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Providers;

use App\Modules\Assistant\Contracts\AiProvider;
use App\Modules\Assistant\Contracts\SpeechProvider;
use App\Modules\Assistant\Drivers\AnthropicProvider;
use App\Modules\Assistant\Drivers\OpenAiProvider;
use App\Modules\Assistant\Drivers\OpenAiSpeechProvider;
use App\Modules\Assistant\Exceptions\AiProviderException;
use App\Modules\Assistant\Exceptions\SpeechException;
use App\Modules\Assistant\Tools\AddProjectMemberTool;
use App\Modules\Assistant\Tools\CancelMeetingTool;
use App\Modules\Assistant\Tools\CreateProjectTool;
use App\Modules\Assistant\Tools\CreateTaskTool;
use App\Modules\Assistant\Tools\CreateWorkspaceTool;
use App\Modules\Assistant\Tools\DeleteTaskTool;
use App\Modules\Assistant\Tools\EditMeetingTool;
use App\Modules\Assistant\Tools\FindTasksTool;
use App\Modules\Assistant\Tools\GetWorkspaceInfoTool;
use App\Modules\Assistant\Tools\InvitationTool;
use App\Modules\Assistant\Tools\ListMeetingsTool;
use App\Modules\Assistant\Tools\ListProjectsTool;
use App\Modules\Assistant\Tools\ManageSprintTool;
use App\Modules\Assistant\Tools\ScheduleMeetingTool;
use App\Modules\Assistant\Tools\SprintReportTool;
use App\Modules\Assistant\Tools\ToolRegistry;
use App\Modules\Assistant\Tools\UpdateTaskTool;
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
        FindTasksTool::class,
        UpdateTaskTool::class,
        DeleteTaskTool::class,
        ScheduleMeetingTool::class,
        EditMeetingTool::class,
        CancelMeetingTool::class,
        AddProjectMemberTool::class,
        SprintReportTool::class,
        ManageSprintTool::class,
    ];

    public function register(): void
    {
        $this->app->bind(AiProvider::class, fn () => match (config('assistant.driver')) {
            'anthropic' => new AnthropicProvider(
                apiKey: (string) config('assistant.anthropic.api_key'),
                baseUrl: (string) config('assistant.anthropic.base_url'),
                maxTokens: (int) config('assistant.anthropic.max_tokens'),
                effort: (string) config('assistant.anthropic.effort'),
                thinking: (bool) config('assistant.anthropic.thinking'),
            ),
            'openai' => new OpenAiProvider(
                apiKey: (string) config('assistant.openai.api_key'),
                baseUrl: (string) config('assistant.openai.base_url'),
            ),
            default => throw AiProviderException::unknownDriver((string) config('assistant.driver')),
        });

        $this->app->bind(SpeechProvider::class, fn () => match (config('assistant.speech.driver')) {
            'openai' => new OpenAiSpeechProvider(
                apiKey: (string) config('assistant.speech.openai.api_key'),
                baseUrl: (string) config('assistant.speech.openai.base_url'),
                model: (string) config('assistant.speech.openai.model'),
                defaultVoice: (string) config('assistant.speech.openai.voice'),
                format: (string) config('assistant.speech.openai.format'),
                speed: (float) config('assistant.speech.openai.speed'),
                timeout: (int) config('assistant.speech.openai.timeout'),
            ),
            default => throw SpeechException::notConfigured((string) config('assistant.speech.driver')),
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

        /*
         * Transcription is cheap next to a chat round but a user speaks more
         * often than they type, so it gets its own, looser bucket.
         */
        RateLimiter::for('assistant-voice', fn (Request $request) => [
            Limit::perMinute((int) config('assistant.rate_limits.voice_per_minute'))
                ->by($request->user()?->id ?: $request->ip()),
        ]);
    }
}
