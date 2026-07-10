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
        'auth_scheme' => env('PANDA_AUTH_SCHEME', ''),
        'folders_path' => env('PANDA_FOLDERS_PATH', '/folders'),
        'folder_name_field' => env('PANDA_FOLDER_NAME_FIELD', 'name'),
        'folder_parent_payload_key' => env('PANDA_FOLDER_PARENT_PAYLOAD_KEY', ''),
        'folder_parent_query_param' => env('PANDA_FOLDER_PARENT_QUERY_PARAM', ''),
        'videos_path' => env('PANDA_VIDEOS_PATH', '/videos'),
        'video_create_path' => env('PANDA_VIDEO_CREATE_PATH', '/videos'),
        'video_upload_path' => env('PANDA_VIDEO_UPLOAD_PATH', '/videos'),
        'video_binary_upload_path' => env('PANDA_VIDEO_BINARY_UPLOAD_PATH', '/videos/{id}'),
        'video_upload_mode' => env('PANDA_VIDEO_UPLOAD_MODE', 'tus'),
        'video_file_field' => env('PANDA_VIDEO_FILE_FIELD', 'file'),
        'video_title_field' => env('PANDA_VIDEO_TITLE_FIELD', 'title'),
        'video_folder_field' => env('PANDA_VIDEO_FOLDER_FIELD', 'folder_id'),
        'video_upload_timeout' => env('PANDA_VIDEO_UPLOAD_TIMEOUT', 600),
        'video_upload_delay_seconds' => env('PANDA_VIDEO_UPLOAD_DELAY_SECONDS', 0),
        'video_upload_retry_attempts' => env('PANDA_VIDEO_UPLOAD_RETRY_ATTEMPTS', 1),
        'video_upload_retry_delay_seconds' => env('PANDA_VIDEO_UPLOAD_RETRY_DELAY_SECONDS', 0),
        'queue_drive_uploads' => env('PANDA_QUEUE_DRIVE_UPLOADS', false),
        'video_upload_job_delay_seconds' => env('PANDA_VIDEO_UPLOAD_JOB_DELAY_SECONDS', 120),
        'video_upload_job_backoff_seconds' => env('PANDA_VIDEO_UPLOAD_JOB_BACKOFF_SECONDS', '300,600,1200,2400'),
        'video_status_sync_delay_seconds' => env('PANDA_VIDEO_STATUS_SYNC_DELAY_SECONDS', 300),
        'video_status_sync_backoff_seconds' => env('PANDA_VIDEO_STATUS_SYNC_BACKOFF_SECONDS', '300,600,1200,2400,3600'),
        'uploader_base_url' => env('PANDA_UPLOADER_BASE_URL', 'https://uploader.pandavideo.com'),
        'uploader_path' => env('PANDA_UPLOADER_PATH', '/files/'),
        'uploader_auth_scheme' => env('PANDA_UPLOADER_AUTH_SCHEME', env('PANDA_AUTH_SCHEME', '')),
        'uploader_user_id' => env('PANDA_UPLOADER_USER_ID'),
        'uploader_video_lookup_attempts' => env('PANDA_UPLOADER_VIDEO_LOOKUP_ATTEMPTS', 6),
        'uploader_video_lookup_delay_seconds' => env('PANDA_UPLOADER_VIDEO_LOOKUP_DELAY_SECONDS', 2),
        'ai_workflow_path' => env('PANDA_AI_WORKFLOW_PATH', '/aiworkflow'),
        'ai_config_base_url' => env('PANDA_AI_CONFIG_BASE_URL', 'https://config.tv.pandavideo.com.br'),
        'ai_from_lang' => env('PANDA_AI_FROM_LANG', 'pt-BR'),
        'ai_package_type' => env('PANDA_AI_PACKAGE_TYPE', 'ALL_TEXT_ITEMS'),
        'ai_regeneration_poll_delay_minutes' => env('PANDA_AI_REGENERATION_POLL_DELAY_MINUTES', 10),
        'ai_sync_backoff_seconds' => env('PANDA_AI_SYNC_BACKOFF_SECONDS', '300,600,1200,2400'),
        'ai_auto_sync' => env('PANDA_AI_AUTO_SYNC', true),
        'tutor_auto_detect' => env('PANDA_TUTOR_AUTO_DETECT', true),
        'tutor_create_path' => env('PANDA_TUTOR_CREATE_PATH', '/assist-ai/buy_and_create'),
        'tutor_show_path' => env('PANDA_TUTOR_SHOW_PATH', '/assist-ai/assistant_by_id/{id}'),
        'tutor_update_path' => env('PANDA_TUTOR_UPDATE_PATH', '/assist-ai/update_assistant_info/{id}'),
        'tutor_chat_visibility_path' => env('PANDA_TUTOR_CHAT_VISIBILITY_PATH', '/assist-ai/update_chat_visibility'),
        'tutor_message' => env('PANDA_TUTOR_MESSAGE', 'Converse com a tutora LilIA'),
        'tutor_sync_backoff_seconds' => env('PANDA_TUTOR_SYNC_BACKOFF_SECONDS', '300,600,1200,2400'),
        'folder_query_param' => env('PANDA_FOLDER_QUERY_PARAM', 'folder_id'),
        'embed_base_url' => env('PANDA_EMBED_BASE_URL'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
    ],

    'google_drive' => [
        'enabled' => env('GOOGLE_DRIVE_ENABLED', false),
        'credentials_path' => env('GOOGLE_DRIVE_CREDENTIALS_PATH'),
        'scopes' => env('GOOGLE_DRIVE_SCOPES', 'https://www.googleapis.com/auth/drive.readonly'),
        'api_base_url' => env('GOOGLE_DRIVE_API_BASE_URL', 'https://www.googleapis.com/drive/v3'),
        'download_timeout' => env('GOOGLE_DRIVE_DOWNLOAD_TIMEOUT', 7200),
        'download_retry_attempts' => env('GOOGLE_DRIVE_DOWNLOAD_RETRY_ATTEMPTS', 3),
        'download_retry_delay_seconds' => env('GOOGLE_DRIVE_DOWNLOAD_RETRY_DELAY_SECONDS', 5),
    ],

];
