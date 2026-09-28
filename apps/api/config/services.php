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
        // Claude 구독(Claude Code) 연결기 토큰이 있으면 관리 화면에 '연결기 설정됨'으로 보인다
        'claude_bridge_token' => env('CLAUDE_BRIDGE_TOKEN'),
        // 최근 1시간 AI 호출 한도(0이면 끔). 반복 실행 같은 사고로 구독 사용량이 새지 않게 하는 안전장치
        'max_calls_per_hour' => (int) env('LLM_MAX_CALLS_PER_HOUR', 60),
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'openai_key' => env('OPENAI_API_KEY'),
        'route' => env('LLM_ROUTE', 'claude_code:opus,codex:gpt-6-astra'),
    ],

    'ai_worker' => [
        'url' => env('AI_WORKER_URL', 'http://ai-worker:8000'),
    ],

];
