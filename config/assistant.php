<?php

declare(strict_types=1);

/**
 * Assistant module configuration.
 *
 * Override per-environment via .env. The defaults here are the production
 * recommended starting point.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | Which AI provider to use by default. Supported: openai, gemini.
    | Switch this in an outage to instantly fail over.
    |
    */
    'driver' => env('ASSISTANT_DRIVER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Default model
    |--------------------------------------------------------------------------
    |
    | Recommendation for production:
    |   - gpt-4o-mini: cheap, fast, surprisingly capable. Default this.
    |   - gpt-4o: use for complex reasoning or important tool calls.
    |   - Route at the orchestration layer based on conversation intent.
    |
    */
    'default_model' => env('ASSISTANT_DEFAULT_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI configuration
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

        // Org and project IDs are optional but recommended for cost
        // attribution in OpenAI dashboards.
        'organization' => env('OPENAI_ORGANIZATION'),
        'project' => env('OPENAI_PROJECT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini configuration
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost caps
    |--------------------------------------------------------------------------
    |
    | Hard ceiling on per-user daily cost. Once hit, the user gets a clean
    | error and we don't call the LLM. Protects against runaway costs
    | from compromised accounts or buggy automation.
    |
    | Values are USD cents. Adjust per pricing plan.
    |
    */
    'cost_caps' => [
        'free_tier_daily_cents' => 50,    // $0.50/day for free users
        'pro_tier_daily_cents' => 500,    // $5.00/day for paid users
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation history depth
    |--------------------------------------------------------------------------
    |
    | How many recent messages to include when building the LLM context.
    | Higher = better continuity, more tokens (= cost). Lower = more
    | forgetful, cheaper. 30 is a good default for chat workloads.
    |
    */
    'history_depth' => 30,

];
