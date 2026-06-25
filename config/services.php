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

    'tutory' => [
        'webhook_secret' => env('TUTORY_WEBHOOK_SECRET', ''),
        'webhook_url' => env('TUTORY_WEBHOOK_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/webhooks/tutory'),
    ],

    'panda' => [
        'api_key' => env('PANDA_API_KEY'),
        'base_url' => env('PANDA_API_BASE_URL', 'https://api-v2.pandavideo.com.br'),
        'auth_header' => env('PANDA_AUTH_HEADER', 'Authorization'),
        'auth_scheme' => env('PANDA_AUTH_SCHEME', 'Bearer'),
        'folders_path' => env('PANDA_FOLDERS_PATH', '/folders'),
        'videos_path' => env('PANDA_VIDEOS_PATH', '/videos'),
        'ai_workflow_path' => env('PANDA_AI_WORKFLOW_PATH', '/aiworkflow'),
        'ai_config_base_url' => env('PANDA_AI_CONFIG_BASE_URL', 'https://config.tv.pandavideo.com.br'),
        'ai_auto_sync' => env('PANDA_AI_AUTO_SYNC', true),
        'tutor_auto_detect' => env('PANDA_TUTOR_AUTO_DETECT', true),
        'folder_query_param' => env('PANDA_FOLDER_QUERY_PARAM', 'folder_id'),
        'embed_base_url' => env('PANDA_EMBED_BASE_URL'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
    ],

];
