<?php

/*
| App nativo da KazaKora (Expo, iOS e Android; 2026-10-18), rotas /api/app.
| Contrato em kazakora-app/docs/api.md.
*/

return [
    // Versão mínima do app: abaixo dela o app pede para atualizar na loja.
    'versao_minima' => [
        'ios' => env('APP_MOBILE_VERSAO_MINIMA_IOS', '1.0.0'),
        'android' => env('APP_MOBILE_VERSAO_MINIMA_ANDROID', '1.0.0'),
    ],

    // Validade do token de login do app (dias). Vencido, o app pede login de novo.
    'token_dias' => (int) env('APP_MOBILE_TOKEN_DIAS', 365),

    // WhatsApp da loja (o mesmo de resources/js/Shared/company.js).
    'whatsapp_numero' => '5511965723990',
];
