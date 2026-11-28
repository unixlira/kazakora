<?php

/*
 * "Compre 1, o 2º sai por R$ X" (pedido do Lira em 27/11/2026), só na loja
 * KazaKora, com o cupom do dia (prefixo + DDMM, ex. LEVE22811) criado pelo
 * comando loja:cupom-leve2 à meia-noite de São Paulo.
 *
 * Preço da 2ª unidade (LeveDois::precoSegunda), pior caso entre cartão e Pix:
 *
 *   (custo + frete incremental + lucro) / (fatia que sobra depois de taxa do
 *   pagamento, desconto do Pix do produto e imposto do Simples)
 *
 * arredondado para cima em ,90 ou ,99. Taxas: as mesmas da conta de lucro
 * (config/ofertas.php e a alíquota de ContributionMargin::aliquotaSimples()).
 * Produto sem custo ou com o resultado acima do preço normal fica fora.
 */
return [
    'ativo' => (bool) env('LEVE2_ATIVO', true),
    'prefixo' => env('LEVE2_PREFIXO', 'LEVE2'),

    // Lucro que tem que sobrar na 2ª unidade, em R$.
    'lucro' => (float) env('LEVE2_LUCRO', 20),

    // Frete incremental da 2ª unidade (vai na mesma caixa), em R$ por kg
    // tarifável começado do produto (mínimo 1 kg). Padrão: o frete do pior
    // caso da Oferta do Dia (ofertas.frete_estimado, R$ 25), aplicado a cada
    // kg a mais na caixa — bem acima do que os Correios cobram por 1 kg a
    // mais, inclusive no Norte. Produto pesado fica caro e sai da promoção.
    'frete_por_kg' => env('LEVE2_FRETE_POR_KG') !== null ? (float) env('LEVE2_FRETE_POR_KG') : null,
];
