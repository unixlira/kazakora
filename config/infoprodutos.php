<?php

/*
 * Medição do funil dos infoprodutos (pedido do Lira em 2026-11-19).
 *
 * Os sites (digitalmktseller.com e elcodigodivinoamen.com) mandam eventos
 * anônimos para POST /api-infoprodutos/evento, sem cookie e sem IP; a
 * Kiwify avisa as vendas em POST /api-infoprodutos/kiwify/webhook. O painel
 * fica em Admin › Publicidade › Infoprodutos.
 */
return [

    // Domínios que podem mandar eventos (Origin ou Referer), sem www. As lojas
    // entram para o mapa de cliques das páginas de produto (kz/<slug>, fk/<slug>).
    'origens' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'INFOPRODUTOS_ORIGENS',
        'digitalmktseller.com,elcodigodivinoamen.com,kazakora.com,ferrakora.com',
    ))))),

    // Produtos (slug do site => nome no painel).
    'produtos' => [
        'abc-en-mis-manos' => 'Busy Book (ABC en mis manos)',
        'agenda-serena-2027' => 'Agenda Serena 2027',
        'pidelo-bien' => 'Pídelo Bien',
        'jarvis' => 'Jarvis',
        'codigo-divino' => 'El Código Divino',
    ],

    // Eventos brutos: apagados depois deste prazo (os totais diários ficam).
    'retencao_dias' => 90,

    // utm_source que marca visita de teste (fica fora do painel e é apagável).
    'utm_teste' => 'teste-medicao',

    /*
     * Kiwify. Token do webhook (Apps › Webhooks na Kiwify): a Kiwify assina
     * cada aviso com ?signature= (HMAC-SHA1 do corpo com o token). Aceita
     * mais de um, separados por vírgula (um por webhook).
     */
    'kiwify' => [
        'tokens' => array_values(array_filter(array_map('trim', explode(',', (string) env('KIWIFY_WEBHOOK_TOKEN', ''))))),

        // product_id da Kiwify => [produto principal, tipo]. Tipo: principal,
        // order_bump, upsell, downsell. Produto que não estiver aqui cai na
        // busca pelo nome (abaixo) e, sem achar, fica como "outro".
        'produtos' => [
            // Busy Book
            '546121e0-e3df-11f1-88e1-77d7a6d4deac' => ['abc-en-mis-manos', 'order_bump'], // numeros-1-al-20
            '8d31a850-e3df-11f1-b479-3f89c662da05' => ['abc-en-mis-manos', 'order_bump'], // formas-y-colores
            'a051bec0-e3df-11f1-bf8d-b5754714ca38' => ['abc-en-mis-manos', 'order_bump'], // animales-y-sus-sonidos
            'b36949b0-e3df-11f1-99b3-d5f3c4e1bd41' => ['abc-en-mis-manos', 'order_bump'], // trazos-y-motricidad-fina
            'ca954b70-e3df-11f1-99b3-d5f3c4e1bd41' => ['abc-en-mis-manos', 'upsell'], // pack-preescolar-completo
            'ddc0faa0-e3df-11f1-b23e-7fbd9890a458' => ['abc-en-mis-manos', 'downsell'], // pack-preescolar-mini
            // Agenda Serena
            'f20e6d80-e3df-11f1-9fee-495c52c8b6eb' => ['agenda-serena-2027', 'order_bump'], // planner-financiero-2027
            '05e32df0-e3e0-11f1-9fee-495c52c8b6eb' => ['agenda-serena-2027', 'order_bump'], // metas-y-habitos-2027
            '1dfac830-e3e0-11f1-b479-3f89c662da05' => ['agenda-serena-2027', 'order_bump'], // menu-semanal-y-compras
            '313f2f80-e3e0-11f1-b23e-7fbd9890a458' => ['agenda-serena-2027', 'order_bump'], // planner-estudio-trabajo-pomodoro
            'e7decee0-e3e9-11f1-9fee-495c52c8b6eb' => ['agenda-serena-2027', 'upsell'], // agenda-serena-editable
            '442faa70-e3e0-11f1-99b3-d5f3c4e1bd41' => ['agenda-serena-2027', 'downsell'], // portadas-y-stickers
            // Pídelo Bien
            '5761e950-e3e0-11f1-b479-3f89c662da05' => ['pidelo-bien', 'order_bump'], // prompts-excel-avanzado
            '6f99b5c0-e3e0-11f1-99b3-d5f3c4e1bd41' => ['pidelo-bien', 'order_bump'], // prompts-correos-y-reuniones
            '82cae330-e3e0-11f1-b479-3f89c662da05' => ['pidelo-bien', 'order_bump'], // prompts-presentaciones-powerpoint
            '9620afa0-e3e0-11f1-99b3-d5f3c4e1bd41' => ['pidelo-bien', 'order_bump'], // prompts-por-area
            'a9477cd0-e3e0-11f1-bf8d-b5754714ca38' => ['pidelo-bien', 'upsell'], // kit-productividad-ia
            'bccae8f0-e3e0-11f1-88e1-77d7a6d4deac' => ['pidelo-bien', 'downsell'], // prompts-chatgpt-gemini
        ],

        // Busca pelo nome do produto na Kiwify (minúsculo, sem acento), na ordem.
        'nomes' => [
            ['expansion agencia', 'jarvis', 'upsell'],
            ['procesos de tu agencia', 'jarvis', 'downsell'],
            ['jarvis', 'jarvis', 'principal'],
            ['pack preescolar completo', 'abc-en-mis-manos', 'upsell'],
            ['pack preescolar mini', 'abc-en-mis-manos', 'downsell'],
            ['busy book', 'abc-en-mis-manos', 'principal'],
            ['abc en mis manos', 'abc-en-mis-manos', 'principal'],
            ['agenda serena 2027 editable', 'agenda-serena-2027', 'upsell'],
            ['portadas', 'agenda-serena-2027', 'downsell'],
            ['agenda serena', 'agenda-serena-2027', 'principal'],
            ['kit productividad', 'pidelo-bien', 'upsell'],
            ['prompts para chatgpt y gemini', 'pidelo-bien', 'downsell'],
            ['pidelo bien', 'pidelo-bien', 'principal'],
            ['biblia 90', 'codigo-divino', 'order_bump'],
            ['glosario', 'codigo-divino', 'order_bump'],
            ['vencer la duda', 'codigo-divino', 'order_bump'],
            ['versiculos', 'codigo-divino', 'order_bump'],
            ['codigo divino', 'codigo-divino', 'principal'],
        ],
    ],

    // Chrome headless dos prints do mapa de cliques (PrintDaPagina).
    'chrome' => env('COMPORTAMENTO_CHROME', '/opt/chrome-headless-shell/chrome-headless-shell'),

    /*
     * Conta de anúncios da Meta dos infoprodutos (GreenKora), lida com o
     * token próprio dela (não é o mesmo usuário do sistema da KazaKora).
     * Só leitura: gasto por anúncio para custo por visita/compra e ROAS.
     */
    'meta' => [
        'ad_account_id' => env('META_GK_AD_ACCOUNT_ID', 'act_1996921014601792'),
        'access_token' => env('META_GK_ACCESS_TOKEN'),
    ],
];
