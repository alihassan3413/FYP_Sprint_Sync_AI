<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Providers;

use App\Modules\Meetings\Contracts\TranscriptionProvider;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Policies\MeetingPolicy;
use App\Modules\Meetings\Support\NullTranscriptionProvider;
use App\Modules\Meetings\Support\OpenAiTranscriptionProvider;
use App\Support\Modules\ModuleServiceProvider;

final class MeetingsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Meetings';

    protected array $policies = [
        Meeting::class => MeetingPolicy::class,
    ];

    public function register(): void
    {
        $this->app->bind(TranscriptionProvider::class, fn () => match (config('transcription.driver')) {
            'openai' => new OpenAiTranscriptionProvider,
            default => new NullTranscriptionProvider,
        });
    }
}
