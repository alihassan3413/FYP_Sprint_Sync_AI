<?php

declare(strict_types=1);

return [
    'driver' => env('ASSISTANT_DRIVER', 'anthropic'),

    'default_model' => env('ASSISTANT_DEFAULT_MODEL', 'claude-sonnet-5'),

    'allowed_models' => [
        'claude-sonnet-5',
        'claude-haiku-4-5',
        'gpt-4o-mini',
        'gpt-4o',
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'max_tokens' => (int) env('ASSISTANT_ANTHROPIC_MAX_TOKENS', 4096),

        /*
         * Effort is Claude's intelligence-versus-latency dial. 'medium' keeps
         * tool selection reliable while staying fast enough to speak aloud.
         */
        'effort' => env('ASSISTANT_ANTHROPIC_EFFORT', 'medium'),

        /*
         * Leave thinking off unless assistant_messages also persists thinking
         * blocks. History is replayed from the database each round, and a
         * thinking turn containing a tool_use fails validation without them.
         */
        'thinking' => (bool) env('ASSISTANT_ANTHROPIC_THINKING', false),
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
        'voice_per_minute' => (int) env('ASSISTANT_VOICE_RATE_PER_MINUTE', 20),
    ],

    /*
     * Text-to-speech for assistant replies. The browser falls back to its own
     * speech synthesis whenever this is unconfigured or a request fails, so
     * the assistant still talks - it just sounds less polished.
     */
    'speech' => [
        'driver' => env('ASSISTANT_SPEECH_DRIVER', 'openai'),

        /*
         * Longest utterance sent in one request. Replies are split into
         * sentence-sized chunks client-side; this is a backstop against a
         * single request running up an unexpected bill.
         */
        'max_characters' => (int) env('ASSISTANT_SPEECH_MAX_CHARS', 1000),

        'allowed_voices' => [
            'alloy', 'ash', 'ballad', 'coral', 'echo',
            'fable', 'nova', 'onyx', 'sage', 'shimmer',
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            /*
             * tts-1 is OpenAI's low-latency voice, built for real-time playback.
             * gpt-4o-mini-tts sounds richer but takes noticeably longer to
             * return the first byte, and the assistant is spoken over a live
             * conversation where waiting reads as the app being stuck. Set
             * ASSISTANT_SPEECH_MODEL=gpt-4o-mini-tts to trade speed for polish.
             */
            'model' => env('ASSISTANT_SPEECH_MODEL', 'tts-1'),
            'voice' => env('ASSISTANT_SPEECH_VOICE', 'nova'),
            'format' => env('ASSISTANT_SPEECH_FORMAT', 'mp3'),
            'speed' => (float) env('ASSISTANT_SPEECH_SPEED', 1.0),
            'timeout' => (int) env('ASSISTANT_SPEECH_TIMEOUT', 30),
        ],
    ],

    /*
     * Speech-to-text for the assistant. Reuses the Meetings transcription
     * provider.
     */
    'voice' => [
        'max_upload_kilobytes' => (int) env('ASSISTANT_VOICE_MAX_UPLOAD_KB', 10240),

        'allowed_mimetypes' => [
            'audio/webm',
            'audio/ogg',
            'audio/mp4',
            'audio/mpeg',
            'audio/mpga',
            'audio/wav',
            'audio/x-wav',
            'video/webm',
        ],
    ],

    'cost_caps' => [
        'free_tier_daily_cents' => (int) env('ASSISTANT_DAILY_CAP_CENTS', 50),
        'pro_tier_daily_cents' => (int) env('ASSISTANT_PRO_DAILY_CAP_CENTS', 500),
    ],

    /*
     * Cents per 1,000,000 tokens. Claude Sonnet 5 is listed at its standard
     * $3 / $15 per MTok rate rather than the introductory rate, so the daily
     * cap does not under-count once the promotion ends.
     */
    'pricing' => [
        'default' => ['input' => 250.0, 'output' => 1000.0],
        'claude-sonnet-5' => ['input' => 300.0, 'output' => 1500.0],
        'claude-haiku-4-5' => ['input' => 100.0, 'output' => 500.0],
        'gpt-4o-mini' => ['input' => 15.0, 'output' => 60.0],
        'gpt-4o' => ['input' => 250.0, 'output' => 1000.0],
    ],

    'retention' => [
        'conversation_days' => (int) env('ASSISTANT_CONVERSATION_RETENTION_DAYS', 30),
        'tool_result_days' => (int) env('ASSISTANT_TOOL_RESULT_RETENTION_DAYS', 7),
    ],

    'history_depth' => (int) env('ASSISTANT_HISTORY_DEPTH', 30),

    'max_tool_rounds' => (int) env('ASSISTANT_MAX_TOOL_ROUNDS', 5),

    'stream_timeout' => (int) env('ASSISTANT_STREAM_TIMEOUT', 180),
];
