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

    // ACHADO REAL 2026-11-09 (#3978): sem o parâmetro "services" o
    // me/shipment/calculate devolve UM serviço só (a Loggi Ponto), e a árvore
    // de 115 cm ficava sem frete. Com a lista, volta cada serviço, com preço
    // ou com o motivo da recusa. IDs de GET me/shipment/services.
    'servicos' => env('MELHORENVIO_SERVICOS', '1,2,3,4,12,15,16,17,22,27,31,32,33,34,35'),

    // Nome do remetente na etiqueta (CNPJ e endereço continuam os da empresa).
    'remetente' => env('MELHORENVIO_REMETENTE', 'KazaKora'),

    'log_channel' => env('MELHORENVIO_LOG_CHANNEL', 'melhorenvio'),

    // Melhor Envio exige um User-Agent identificando a aplicação + um
    // contato válido — requests sem isso podem ser rejeitadas.
    'user_agent' => env('MELHORENVIO_USER_AGENT', 'KazaKora (contato@kazakora.com)'),
];
