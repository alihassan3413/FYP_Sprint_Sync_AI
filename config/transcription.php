<?php

declare(strict_types=1);

return [
    'driver' => env('TRANSCRIPTION_DRIVER', 'openai'),

    'disk' => env('TRANSCRIPTION_DISK', 'local'),

    'max_upload_kilobytes' => (int) env('TRANSCRIPTION_MAX_UPLOAD_KB', 51200),

    'allowed_mimetypes' => [
        'audio/mpeg',
        'audio/mp4',
        'audio/mpga',
        'audio/wav',
        'audio/x-wav',
        'audio/webm',
        'audio/ogg',
        'video/mp4',
        'video/webm',
    ],

    'low_confidence_threshold' => (int) env('TRANSCRIPTION_LOW_CONFIDENCE_THRESHOLD', 65),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('TRANSCRIPTION_MODEL', 'whisper-1'),
        'timeout' => (int) env('TRANSCRIPTION_TIMEOUT', 300),
    ],
];
