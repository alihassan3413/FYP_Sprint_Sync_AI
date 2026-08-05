<?php

declare(strict_types=1);

return [
    'driver' => env('ASSISTANT_DRIVER', 'openai'),

    'default_model' => env('ASSISTANT_DEFAULT_MODEL', 'gpt-4o-mini'),

    'allowed_models' => [
        'gpt-4o-mini',
        'gpt-4o',
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'project' => env('OPENAI_PROJECT'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'rate_limits' => [
        'per_minute' => (int) env('ASSISTANT_RATE_PER_MINUTE', 10),
        'per_day' => (int) env('ASSISTANT_RATE_PER_DAY', 200),
    ],

    'cost_caps' => [
        'free_tier_daily_cents' => (int) env('ASSISTANT_DAILY_CAP_CENTS', 50),
        'pro_tier_daily_cents' => (int) env('ASSISTANT_PRO_DAILY_CAP_CENTS', 500),
    ],

    'pricing' => [
        'default' => ['input' => 250.0, 'output' => 1000.0],
        'gpt-4o-mini' => ['input' => 15.0, 'output' => 60.0],
        'gpt-4o' => ['input' => 250.0, 'output' => 1000.0],
    ],

    'history_depth' => (int) env('ASSISTANT_HISTORY_DEPTH', 30),

    'max_tool_rounds' => (int) env('ASSISTANT_MAX_TOOL_ROUNDS', 5),

    'stream_timeout' => (int) env('ASSISTANT_STREAM_TIMEOUT', 180),
];
