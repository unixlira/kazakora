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

    // Onde levar os pacotes (2026-11-09): ponto que aceita quase todas as
    // transportadoras, a 200 m da loja (Pegaki DISTRIBUE, Rua Florianópolis,
    // 198A). Transportadora que ele não aceita vai na agência mais próxima
    // dela; distância medida a partir da loja (lat/lon abaixo).
    'ponto_padrao' => (int) env('MELHORENVIO_PONTO_PADRAO', 41970),
    'loja_lat' => (float) env('MELHORENVIO_LOJA_LAT', -23.5606),
    'loja_lon' => (float) env('MELHORENVIO_LOJA_LON', -46.5826),

    'log_channel' => env('MELHORENVIO_LOG_CHANNEL', 'melhorenvio'),

    // Melhor Envio exige um User-Agent identificando a aplicação + um
    // contato válido — requests sem isso podem ser rejeitadas.
    'user_agent' => env('MELHORENVIO_USER_AGENT', 'KazaKora (contato@kazakora.com)'),
];
