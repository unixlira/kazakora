<?php

/*
 * Ofertas do dia (pedido 2026-10-10): todo dia 5 produtos com mais de 25% de
 * desconto ganham +5 pontos de desconto, só na loja KazaKora. A oferta só
 * entra se, no pior caso (Pix com o desconto dele, taxa do gateway e o frete
 * grátis pago pela loja), ainda sobra a margem mínima — nunca prejuízo.
 * Valores ajustáveis pelo .env sem mexer em código.
 */
return [
    'quantidade' => (int) env('OFERTAS_QUANTIDADE', 5),
    'desconto_minimo' => (float) env('OFERTAS_DESCONTO_MINIMO', 25),
    'desconto_extra' => (float) env('OFERTAS_DESCONTO_EXTRA', 5),
    // Não repetir o mesmo produto antes de X dias.
    'dias_sem_repetir' => (int) env('OFERTAS_DIAS_SEM_REPETIR', 3),
    // Custos usados na conta de lucro (pior caso).
    'taxa_cartao' => (float) env('OFERTAS_TAXA_CARTAO', 4.98),
    'taxa_pix' => (float) env('OFERTAS_TAXA_PIX', 0.99),
    'frete_estimado' => (float) env('OFERTAS_FRETE_ESTIMADO', 25),
    // Lucro mínimo que tem que sobrar, em % do preço da oferta.
    'margem_minima' => (float) env('OFERTAS_MARGEM_MINIMA', 10),
    // Sem 5 produtos acima do desconto mínimo, completa com os de melhor
    // margem que aguentam o desconto extra (a seção nunca fica vazia).
    'completar_com_margem' => (bool) env('OFERTAS_COMPLETAR', true),
];
