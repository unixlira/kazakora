<?php

/*
 * CORS de /api/* (auditoria de segurança 2026-11-25).
 *
 * Antes não existia este arquivo e valia o padrão do Laravel: qualquer
 * origem ('*'). Agora só os sites próprios leem a API pelo navegador:
 * KazaKora, painel, apps de vendas, FerraKora e o homolog (*.devlira.com.br,
 * onde ficam ferrakora.devlira.com.br e beautykora.devlira.com.br).
 *
 * Os domínios de FERRAKORA_ORIGENS e BEAUTYKORA_ORIGENS (os mesmos que a
 * trava de /api/ferrakora/visita e /api/beautykora/visita usa) entram
 * sozinhos, com e sem www. Origem nova sem mexer no código:
 * CORS_ORIGENS_EXTRAS=https://site1.com,https://site2.com
 *
 * Servidor chamando servidor (webhooks, agentes, apps nativos) não usa CORS
 * e não é afetado. /api-apps-vendas e /api-infoprodutos têm CORS próprio
 * (CorsAppsVendas, CorsInfoprodutos) e ficam fora daqui.
 */

$hosts = [
    'kazakora.com', 'painel.kazakora.com', 'app.kazakora.com',
    'ferrakora.com', 'app.ferrakora.com',
    'devlira.com.br',
];

foreach (['FERRAKORA_ORIGENS', 'BEAUTYKORA_ORIGENS'] as $variavel) {
    foreach (explode(',', (string) env($variavel, '')) as $host) {
        $host = preg_replace('/^www\./', '', strtolower(trim($host)));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host) === 1) {
            $hosts[] = $host;
        }
    }
}

$origens = [];
foreach (array_unique($hosts) as $host) {
    $origens[] = 'https://'.$host;
    $origens[] = 'https://www.'.$host;
}

foreach (explode(',', (string) env('CORS_ORIGENS_EXTRAS', '')) as $extra) {
    $extra = rtrim(trim($extra), '/');
    if ($extra !== '') {
        $origens[] = $extra;
    }
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique($origens)),

    // Homolog: qualquer subdomínio https de devlira.com.br.
    'allowed_origins_patterns' => ['#^https://([a-z0-9-]+\.)+devlira\.com\.br$#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
