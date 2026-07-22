<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mercado Livre API
    |--------------------------------------------------------------------------
    |
    | OAuth credentials/URLs live in config/services.php ('mercadolivre').
    | This file holds request/runtime tuning that isn't per-environment
    | secrets.
    |
    */

    'site_id' => env('ML_SITE_ID', 'MLB'),

    'timeout' => (int) env('ML_HTTP_TIMEOUT', 15),

    'connect_timeout' => (int) env('ML_HTTP_CONNECT_TIMEOUT', 5),

    'retry' => [
        'times' => (int) env('ML_HTTP_RETRY_TIMES', 3),
        'base_delay_ms' => (int) env('ML_HTTP_RETRY_BASE_DELAY_MS', 500),
    ],

    'rate_limit' => [
        // Mercado Livre's published guidance: ~10k requests/hour per app.
        'max_requests_per_hour' => (int) env('ML_RATE_LIMIT_PER_HOUR', 10000),
    ],

    'log_channel' => env('ML_LOG_CHANNEL', 'mercadolivre'),

    'queue' => env('ML_QUEUE', 'mercadolivre'),

    'token_refresh_threshold_minutes' => (int) env('ML_TOKEN_REFRESH_THRESHOLD_MINUTES', 5),
];
