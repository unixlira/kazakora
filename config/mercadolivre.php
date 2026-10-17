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

    // Bug real encontrado 2026-08-01: o cron do homolog roda `queue:work`
    // sem `--queue=`, então só drena a fila "default" — jobs numa fila
    // nomeada nunca são processados (46 webhooks do ML ficaram parados até
    // isso ser descoberto). Default trocado pra "default" pra usar o worker
    // que já funciona; só sai daqui se ML_QUEUE for setado explicitamente.
    'queue' => env('ML_QUEUE', 'default'),

    'token_refresh_threshold_minutes' => (int) env('ML_TOKEN_REFRESH_THRESHOLD_MINUTES', 5),

    /*
    | Cupom do vendedor (SELLER_COUPON_CAMPAIGN), tela Integrações › Mercado
    | Livre › Criar cupom. Modelo validado pelo Lira em 16/10/2026: 50% de
    | desconto, teto de R$ 6 e compra mínima de 2% do ticket médio do ML.
    | Os valores só pré-preenchem a tela; tudo é editável antes de enviar.
    |
    | envio_habilitado: o homolog usa a MESMA conta do ML que a produção
    | (KORAMIX), então criar cupom lá seria cupom de verdade. Sem a variável
    | ML_CUPOM_ENVIO, o envio só fica ligado quando APP_URL é kazakora.com.
    */
    'cupom' => [
        'percentual' => 50,
        'desconto_maximo' => 6.00,
        'compra_minima_pct_ticket' => 2,
        'ticket_dias' => 30,
        'orcamento' => 60.00,
        'duracao_dias' => 30,
        'envio_habilitado' => env('ML_CUPOM_ENVIO'),
    ],
];
