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
];
