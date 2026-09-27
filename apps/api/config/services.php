<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 관리 화면(LlmSettings)에 값이 없을 때 쓰는 기본값
    'llm' => [
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'openai_key' => env('OPENAI_API_KEY'),
        'route' => env('LLM_ROUTE', 'anthropic:claude-opus-5,openai:gpt-5.5'),
    ],

    'ai_worker' => [
        'url' => env('AI_WORKER_URL', 'http://ai-worker:8000'),
    ],

];
