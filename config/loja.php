<?php

return [
    /*
    | Desconto no Pix da loja KazaKora (pedido 2026-10-09). O preço da loja
    | (products.price) já leva esse acréscimo embutido: quem cadastra digita o
    | valor do Pix e o sistema grava +5%. No checkout, pagando 100% no Pix,
    | sai o desconto. Os marketplaces recebem o preço SEM o acréscimo.
    */
    'desconto_pix' => (float) env('LOJA_DESCONTO_PIX', 5),

    /*
    | Selo "Verificada por Reclame AQUI" (2026-10-11). Verificação aprovada:
    | desde 2026-10-14 o selo OFICIAL (componente SeloReclameAqui.vue) aparece
    | sempre no rodapé da loja, no checkout e no menu do admin, sem depender
    | da chave LOJA_SELO_RA_VERIFICADA (mantida só por compatibilidade).
    | reclame_aqui_url é o link do selo (página RA Verificada da loja).
    */
    'selo_ra_verificada' => (bool) env('LOJA_SELO_RA_VERIFICADA', false),
    'reclame_aqui_url' => env('LOJA_RECLAME_AQUI_URL', 'https://www.reclameaqui.com.br/rav/F68R'),

    /*
    | Dados da empresa nos e-mails (rodapé) — os mesmos de
    | resources/js/Shared/company.js.
    */
    'empresa' => [
        'nome' => 'Kazakora | Grupo AlphaKora',
        'cnpj' => '65.604.590/0001-07',
        'whatsapp_exibicao' => '(11) 96572-3990',
        'whatsapp_link' => 'https://wa.me/5511965723990',
        'endereco' => 'Rua Mogi Mirim, 20, Mooca, São Paulo/SP',
    ],

    /*
    | Para onde vão as mensagens do formulário "Fale conosco" (pedido
    | 2026-10-10) — o e-mail do dono da loja.
    */
    'email_contato' => env('LOJA_EMAIL_CONTATO', 'korashopecom@gmail.com'),

    /*
    | E-mail do pagador que vai para o Mercado Pago no Pix (pedido
    | 2026-10-11): um endereço NOSSO por pedido, para o Mercado Pago não
    | mandar e-mail de cobrança ao cliente — quem avisa é a KazaKora
    | (PixAguardandoPagamento). {pedido} vira o número do pedido. Cartão
    | continua com o e-mail real (entra na análise antifraude).
    */
    'email_pagador_pix' => env('MERCADOPAGO_EMAIL_PAGADOR_PIX', 'pedido-{pedido}@kazakora.com'),

    /*
    | Relatórios internos por e-mail (resultado do dia, pedidos cancelados
    | depois de separados). Vários endereços separados por vírgula.
    */
    'email_relatorios' => env('LOJA_EMAIL_RELATORIOS', env('LOJA_EMAIL_CONTATO', 'korashopecom@gmail.com')),

    /*
    | Simples Nacional (pedido 2026-10-10): alíquota efetiva informada pelo
    | contador, em %, e a data em que a empresa entrou no Simples (antes era
    | MEI). Usada para estimar o DAS do mês no e-mail de resultado.
    */
    'simples' => [
        'aliquota' => (float) env('LOJA_SIMPLES_ALIQUOTA', 4.5),
        'desde' => env('LOJA_SIMPLES_DESDE', '2026-09-01'),
    ],
];
