<?php

return [
    /*
    | Desconto no Pix: desde 2026-10-18 é por produto ("Desconto Checkout (%)"
    | no cadastro, products.checkout_discount_percentage), sem % fixo aqui.
    | O preço da loja (products.price) é o valor digitado no cadastro.
    */

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
    | Boleto pelo Mercado Pago (pedido 2026-10-14). Vence em N dias corridos
    | (às 23:59 de São Paulo); sem desconto do Pix e sem dividir com cartão.
    | O mesmo e-mail nosso do Pix vai como pagador, para o Mercado Pago não
    | escrever ao cliente (quem manda o boleto é a KazaKora).
    | boleto_cancelar_apos_dias: se o Mercado Pago ainda disser "pendente"
    | esse tanto de dias DEPOIS do vencimento (compensação leva até 3 dias
    | úteis), a loja cancela o boleto e o pedido e devolve o estoque.
    */
    'boleto_dias_vencimento' => (int) env('LOJA_BOLETO_DIAS_VENCIMENTO', 3),
    'boleto_cancelar_apos_dias' => (int) env('LOJA_BOLETO_CANCELAR_APOS_DIAS', 7),

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

    /*
    | Selo do cliente no Meu Perfil (pedido 2026-10-14): todo cliente começa
    | como "Super Cliente"; com MAIS de `pedidos` pedidos finalizados
    | (entregues) vira "5 ★". Só contam pedidos feitos a partir de `desde`
    | (os anteriores são desconsiderados).
    */
    'selo_cliente' => [
        'pedidos' => (int) env('LOJA_SELO_CLIENTE_PEDIDOS', 10),
        'desde' => env('LOJA_SELO_CLIENTE_DESDE', '2026-10-15'),
    ],
];
