<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mercado Pago API
    |--------------------------------------------------------------------------
    |
    | Access Token/Public Key/webhook secret ficam em config/services.php
    | ('mercadopago'). Este arquivo guarda ajustes de request/runtime.
    |
    */

    'timeout' => (int) env('MERCADOPAGO_HTTP_TIMEOUT', 15),

    'connect_timeout' => (int) env('MERCADOPAGO_HTTP_CONNECT_TIMEOUT', 5),

    'retry' => [
        'times' => (int) env('MERCADOPAGO_HTTP_RETRY_TIMES', 2),
        'base_delay_ms' => (int) env('MERCADOPAGO_HTTP_RETRY_BASE_DELAY_MS', 300),
    ],

    'log_channel' => env('MERCADOPAGO_LOG_CHANNEL', 'mercadopago'),
];
