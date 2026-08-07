<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'mercadolivre' => [
        'app_id' => env('ML_APP_ID'),
        'client_secret' => env('ML_CLIENT_SECRET'),
        'redirect_uri' => env('ML_REDIRECT_URI'),
        'webhook_url' => env('ML_WEBHOOK_URL'),
        'api_base_url' => env('ML_API_BASE_URL', 'https://api.mercadolibre.com'),
        'auth_url' => env('ML_AUTH_URL', 'https://auth.mercadolivre.com.br/authorization'),
        'token_url' => env('ML_TOKEN_URL', 'https://api.mercadolibre.com/oauth/token'),
    ],

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'api_base_url' => env('MERCADOPAGO_API_BASE_URL', 'https://api.mercadopago.com'),
    ],

    'melhorenvio' => [
        'client_id' => env('MELHORENVIO_CLIENT_ID'),
        'client_secret' => env('MELHORENVIO_CLIENT_SECRET'),
        'redirect_uri' => env('MELHORENVIO_REDIRECT_URI'),
        'api_base_url' => env('MELHORENVIO_API_BASE_URL', 'https://sandbox.melhorenvio.com.br/api/v2'),
        'auth_url' => env('MELHORENVIO_AUTH_URL', 'https://sandbox.melhorenvio.com.br/oauth/authorize'),
        'token_url' => env('MELHORENVIO_TOKEN_URL', 'https://sandbox.melhorenvio.com.br/oauth/token'),
    ],

    'print_agent' => [
        'token' => env('PRINT_AGENT_TOKEN'),
    ],

    'shopee' => [
        // Credenciais atuais são de teste/sandbox (SHOPEE_TEST_*, confirmado
        // pelo próprio nome) — pareiam com o host test-stable, não o de
        // produção. Trocar pra produção = trocar as 3 envs junto (partner_id
        // e partner_key reais têm valores diferentes dos de teste).
        'partner_id' => env('SHOPEE_TEST_PARTNER_ID', env('SHOPEE_PARTNER_ID')),
        'partner_key' => env('SHOPEE_TEST_PARTNER_KEY', env('SHOPEE_PARTNER_KEY')),
        'redirect_url' => env('SHOPEE_REDIRECT_URL'),
        // URL fixa (não $request->fullUrl()) porque não há TrustProxies
        // configurado — atrás do Nginx do Hostinger, a URL calculada a
        // partir da request poderia vir como http:// em vez de https://,
        // o que quebraria a validação de assinatura do push silenciosamente.
        'push_url' => env('SHOPEE_PUSH_URL'),
        // Chave separada da partner_key acima — a própria Shopee gera uma
        // "Push Partner Key" específica pra assinar/validar notificações de
        // push, distinta da partner_key usada nas chamadas normais de API
        // (confirmado pelo usuário direto no painel deles, não documentado
        // em lugar nenhum que consegui acessar nesta sessão).
        'push_partner_key' => env('SHOPEE_PUSH_PARTNER_KEY'),
        'api_base_url' => env('SHOPEE_API_BASE_URL', 'https://partner.test-stable.shopeemobile.com'),
        // Host do LINK de autorização (o vendedor clica e é levado pra lá) —
        // achado real 2026-08-06: a Shopee usa um host REGIONAL específico
        // pra esse link em PRODUÇÃO, diferente do api_base_url usado nas
        // chamadas de API (token/get etc.) — confirmado contra a doc
        // oficial (open.shopee.com/developer-guide/20, tabela "Generating
        // the Authorization Link") E contra DNS de verdade: produção
        // Brasil é open.shopee.com.br (resolve, responde 200). A doc
        // TAMBÉM lista um "sandbox Brasil" (open.sandbox.test-stable.
        // shopee.com.br) que na prática NÃO EXISTE (NXDOMAIN, confirmado)
        // — sandbox não tem infraestrutura regional, é sempre o host
        // global mesmo (open.test-stable.shopee.com, sem "sandbox." antes
        // de "test-stable" — esse SIM resolve e responde 302, bate com o
        // exemplo de link da própria doc, só a tabela de hosts é que tá
        // errada/desatualizada nesse ponto específico). Usar o
        // api_base_url aqui (como o código fazia antes) gera um link que a
        // própria Shopee rejeita — foi exatamente o "endpoint errado" que
        // o suporte deles apontou. Ver ShopeeAuthService::getAuthorizationUrl().
        'auth_base_url' => env('SHOPEE_AUTH_BASE_URL', 'https://open.test-stable.shopee.com'),
        // Liga log de diagnóstico da assinatura (base string, sign, fingerprint
        // da key — nunca a key crua) sem precisar redeploy. Usado pra investigar
        // um "Wrong sign" real da Shopee (2026-08-01) — ver ShopeeAuthService.
        'debug_signing' => (bool) env('SHOPEE_DEBUG_SIGNING', false),
    ],

    'amazon' => [
        // "Identificador de cliente" no console do Login with Amazon (LWA) —
        // usado pra trocar/renovar tokens (api.amazon.com/auth/o2/token).
        // Diferente do "App ID" abaixo, que identifica o app SP-API em si
        // (usado só no link de autorização).
        'lwa_client_id' => env('AMAZON_LWA_CLIENT_ID'),
        'lwa_client_secret' => env('AMAZON_LWA_CLIENT_SECRET'),
        // amzn1.sp.solution.xxx — App ID do app SP-API (Seller Central >
        // Develop Apps), usado como application_id no link de autorização
        // (fluxo OAuth completo, ver AmazonAuthService::getAuthorizationUrl()).
        'app_id' => env('AMAZON_APP_ID'),
        'redirect_uri' => env('AMAZON_REDIRECT_URI'),
        // Apps SP-API privados (não publicados na loja de apps) não
        // precisam do redirect OAuth: o próprio vendedor gera um refresh
        // token direto no Seller Central (Partner Network > Develop Apps >
        // Autorizar) e cola no admin — ver
        // AmazonAuthService::connectWithRefreshToken(). Esse valor aqui é
        // só uma conveniência pra bootstrap via .env (o mesmo token colado
        // manualmente fica persistido em marketplace_accounts depois).
        'bootstrap_refresh_token' => env('AMAZON_REFRESH_TOKEN'),
        'bootstrap_seller_id' => env('AMAZON_SELLER_ID'),
        // Brasil fica na região "NA" pra fins de endpoint da SP-API (mesmo
        // host base de US/CA/MX), apesar do marketplace em si ser regional.
        'marketplace_id' => env('AMAZON_MARKETPLACE_ID', 'A2Q3Y263D00KWC'), // Amazon.com.br
        'region' => env('AMAZON_REGION', 'na'),
        'sandbox' => (bool) env('AMAZON_SANDBOX', true),
        'sp_api_base_url' => env(
            'AMAZON_SP_API_BASE_URL',
            (bool) env('AMAZON_SANDBOX', true)
                ? 'https://sandbox.sellingpartnerapi-na.amazon.com'
                : 'https://sellingpartnerapi-na.amazon.com',
        ),
        // Host do link de autorização (fluxo OAuth completo) — .com.br pra
        // vendedor brasileiro logar direto na conta certa. "version=beta" é
        // exigido enquanto o app estiver em estado "Draft" no Seller
        // Central (self-testing antes de publicar).
        'auth_base_url' => env('AMAZON_AUTH_BASE_URL', 'https://sellercentral.amazon.com.br'),
        'auth_draft_mode' => (bool) env('AMAZON_AUTH_DRAFT_MODE', true),
    ],

];
