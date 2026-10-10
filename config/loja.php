<?php

return [
    /*
    | Desconto no Pix da loja KazaKora (pedido 2026-10-09). O preço da loja
    | (products.price) já leva esse acréscimo embutido: quem cadastra digita o
    | valor do Pix e o sistema grava +5%. No checkout, pagando 100% no Pix,
    | sai o desconto. Os marketplaces recebem o preço SEM o acréscimo.
    */
    'desconto_pix' => (float) env('LOJA_DESCONTO_PIX', 5),
];
