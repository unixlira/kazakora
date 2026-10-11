<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Melhor Envio API
    |--------------------------------------------------------------------------
    |
    | Token/URL da conta ficam em config/services.php ('melhorenvio'). Este
    | arquivo guarda ajustes de request/runtime que não são segredo.
    |
    */

    'timeout' => (int) env('MELHORENVIO_HTTP_TIMEOUT', 10),

    'connect_timeout' => (int) env('MELHORENVIO_HTTP_CONNECT_TIMEOUT', 5),

    'retry' => [
        'times' => (int) env('MELHORENVIO_HTTP_RETRY_TIMES', 2),
        'base_delay_ms' => (int) env('MELHORENVIO_HTTP_RETRY_BASE_DELAY_MS', 300),
    ],

    'log_channel' => env('MELHORENVIO_LOG_CHANNEL', 'melhorenvio'),

    // Melhor Envio exige um User-Agent identificando a aplicação + um
    // contato válido — requests sem isso podem ser rejeitadas.
    'user_agent' => env('MELHORENVIO_USER_AGENT', 'KazaKora (contato@kazakora.com)'),
];
