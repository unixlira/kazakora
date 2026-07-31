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

    'melhorenvio' => [
        // Token de Integração gerado no painel do Melhor Envio (Configurações
        // > Tokens) — sem OAuth, é um Bearer token estático por conta.
        'token' => env('MELHORENVIO_TOKEN'),
        'api_base_url' => env('MELHORENVIO_API_BASE_URL', 'https://sandbox.melhorenvio.com.br/api/v2'),
    ],

];
