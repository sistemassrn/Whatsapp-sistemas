<?php

return [
    'base_url' => env('OPENWA_BASE_URL', 'http://127.0.0.1:3000/api'),

    'api_key' => env('OPENWA_API_KEY'),

    'webhook_secret' => env('OPENWA_WEBHOOK_SECRET'),

    'session_name' => env('OPENWA_SESSION_NAME', 'whatsapp-sistemas'),

    'timeout' => (int) env('OPENWA_TIMEOUT', 5),
];
