<?php

return [
    'base_url' => env('OPENWA_BASE_URL', 'http://127.0.0.1:3000/api'),

    'api_key' => env('OPENWA_API_KEY'),

    'webhook_secret' => env('OPENWA_WEBHOOK_SECRET'),

    'require_webhook_secret' => (bool) env('OPENWA_REQUIRE_WEBHOOK_SECRET', env('APP_ENV') === 'production'),

    'session_name' => env('OPENWA_SESSION_NAME', 'whatsapp-sistemas'),

    'timeout' => (int) env('OPENWA_TIMEOUT', 5),

    'media_timeout' => (int) env('OPENWA_MEDIA_TIMEOUT', env('OPENWA_TIMEOUT', 30)),

    'import_max_age_days' => (int) env('OPENWA_IMPORT_MAX_AGE_DAYS', 150),

    'recent_sync_window_hours' => (int) env('OPENWA_RECENT_SYNC_WINDOW_HOURS', 24),

    'retry_media_window_hours' => (int) env('OPENWA_RETRY_MEDIA_WINDOW_HOURS', 240),

    'import_unknown_historical_chats' => (bool) env('OPENWA_IMPORT_UNKNOWN_HISTORICAL_CHATS', false),

    'sync_recent' => [
        'enabled' => (bool) env('WHATSAPP_SYNC_RECENT_ENABLED', false),
        'limit_chats' => (int) env('WHATSAPP_SYNC_RECENT_LIMIT_CHATS', 20),
        'limit_messages' => (int) env('WHATSAPP_SYNC_RECENT_LIMIT_MESSAGES', 20),
    ],

    'retry_media' => [
        'enabled' => (bool) env('WHATSAPP_RETRY_MEDIA_ENABLED', false),
        'limit' => (int) env('WHATSAPP_RETRY_MEDIA_LIMIT', 50),
        'minutes' => (int) env('WHATSAPP_RETRY_MEDIA_MINUTES', 1440),
    ],
];
