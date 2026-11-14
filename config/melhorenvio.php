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

    // Comparar com o contrato dos Correios antes de comprar a etiqueta
    // (2026-11-14, #4172). DESLIGADO por padrão: a diferença que motivou a
    // regra (R$ 16,53 x R$ 14,98) era o valor declarado. O Melhor Envio
    // cota com seguro do valor do pedido e a consulta ao contrato, sem; pelo
    // mesmo pacote sem seguro os dois cobram igual. Ligar só depois de o
    // Lira decidir se os pedidos saem com ou sem valor declarado.
    'comparar_correios' => (bool) env('MELHORENVIO_COMPARAR_CORREIOS', false),

    // Telefone do remetente (2026-11-14, #4172): o de atendimento de cada
    // loja (o mesmo do rodapé do site e dos e-mails), nunca o do cadastro da
    // empresa, que é o pessoal do dono e saiu impresso na etiqueta do SEDEX.
    'telefones' => [
        'kazakora' => env('MELHORENVIO_TELEFONE', '11965723990'),
        'ferrakora' => env('MELHORENVIO_TELEFONE_FERRAKORA', '11927206026'),
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
