<?php

return [
    /*
    | Oferta Relâmpago diária da Shopee (pedido 2026-10-17): todo dia o
    | shopee:relampago-diaria cria a Relâmpago do dia seguinte com os
    | produtos da tabela shopee_relampago_itens (lista do Lira).
    |
    | Desligado por padrão: só a produção liga (SHOPEE_RELAMPAGO_ATIVA=true).
    | Sem isso o homolog, que roda o mesmo agendador, criaria promoção de
    | verdade na loja. Desligado, o comando só roda com --seco (simulação).
    */
    'ativa' => (bool) env('SHOPEE_RELAMPAGO_ATIVA', false),

    // Quantos dias à frente manter programados (1 = só amanhã). A Shopee
    // RESERVA o estoque de cada Relâmpago programada (17/10: lixeiras com 6
    // na Relâmpago de 18/10 ficaram com 0 livre para a de 19/10), então cada
    // dia a mais divide o estoque por mais uma Relâmpago.
    'dias_a_frente' => (int) env('SHOPEE_RELAMPAGO_DIAS', 1),

    /*
    | Item da lista sem unidades definidas entra com esta % do estoque total
    | do anúncio (livre + reservado). Com 100% a Relâmpago de hoje prende tudo
    | e a de amanhã não consegue estoque; 50% deixa as duas no ar.
    */
    'estoque_pct' => (float) env('SHOPEE_RELAMPAGO_ESTOQUE_PCT', 50),

    // Shopee: a Relâmpago tem que ficar pelo menos 1% abaixo do preço atual
    // (get_item_criteria, min_discount = 1; R$ 1,00 foi recusado em item
    // acima de R$ 100 em 17/10).
    'desconto_minimo_pct' => 1.0,

    /*
    | Lucro mínimo por unidade quando o PRÓPRIO sistema calcula ou baixa o
    | preço (regra do Lira 17/10 para a Relâmpago: R$ 10 até R$ 200 e R$ 30
    | acima; Simples 4,5%, sem ADS). Preço fixo definido pelo Lira não é
    | barrado por isso, só por prejuízo.
    */
    'lucro_minimo' => [
        ['ate' => 200.0, 'valor' => 10.0],
        ['ate' => null, 'valor' => 30.0],
    ],

    /*
    | Taxa da Shopee por faixa de preço (comissão % + valor fixo por item),
    | a mesma conta do Cauê em 17/10. Só para estimar o lucro antes de subir;
    | o lucro oficial continua sendo a ContributionMargin com a taxa real.
    */
    'taxas' => [
        ['ate' => 79.99, 'pct' => 20.0, 'fixo' => 4.50],
        ['ate' => 99.99, 'pct' => 14.0, 'fixo' => 16.00],
        ['ate' => 199.99, 'pct' => 14.0, 'fixo' => 20.00],
        ['ate' => null, 'pct' => 14.0, 'fixo' => 26.00],
    ],
];
