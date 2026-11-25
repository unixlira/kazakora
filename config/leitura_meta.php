<?php

/*
 * Leitura das campanhas (Publicidade › Meta Ads, pedido do Lira em
 * 25/11/2026): faixas do semáforo de cada número e as regras do "O que
 * fazer". Tudo aqui é fácil de mudar sem mexer em código.
 *
 * Faixas: "maior é melhor" (hook, CTR) usam verde_min/amarelo_min; "menor é
 * melhor" (CPM, CPC, frequência) usam verde_max/amarelo_max. Os limites
 * entram na cor melhor (hook 30 = verde; CPM 35 = verde; CPM 50 = amarelo).
 * CPA não está aqui: o verde é até o CPA alvo do produto e o amarelo até o
 * CPA máximo (o 0 a 0), calculados por CpaDoProduto.
 */
return [
    'faixas' => [
        // Hook (%): visualizações de 3 s do vídeo ÷ impressões.
        'hook' => ['verde_min' => 30, 'amarelo_min' => 20],
        // Frequência: quantas vezes, em média, a mesma pessoa viu o anúncio.
        'frequencia' => ['verde_max' => 2.5, 'amarelo_max' => 3.5],
        // CPM (R$): custo de mil impressões.
        'cpm' => ['verde_max' => 35, 'amarelo_max' => 50],
        // CTR do link (%): cliques no link ÷ impressões.
        'ctr' => ['verde_min' => 2, 'amarelo_min' => 1],
        // CPC do link (R$): gasto ÷ cliques no link.
        'cpc' => ['verde_max' => 2, 'amarelo_max' => 3],
    ],

    // Funil: abaixo disso a etapa é o problema (só com volume mínimo).
    'funil' => [
        // Visualizações da página ÷ cliques no link (a página demora e a pessoa desiste).
        'pagina_por_clique_min' => 0.6,
        // A partir de quantas visualizações da página sem carrinho a página é o problema.
        'paginas_sem_carrinho' => 30,
    ],

    // Escala e corte (aulas do professor): +20% a cada 2 dias, fraco −20%.
    'ajuste_orcamento' => 0.20,

    // Taxa do pagamento usada no 0 a 0 (cartão, a mais cara: conservador).
    'taxa_pagamento' => (float) env('LEITURA_META_TAXA_PAGAMENTO', env('OFERTAS_TAXA_CARTAO', 4.98)),

    // Janela das vendas do site que dão o preço médio pago e o frete médio real.
    'janela_vendas_dias' => (int) env('LEITURA_META_JANELA_VENDAS', 90),

    // Frete usado quando o produto ainda não tem etiqueta paga na janela (R$).
    'frete_padrao' => (float) env('LEITURA_META_FRETE_PADRAO', env('OFERTAS_FRETE_ESTIMADO', 25)),

    // Coluna "Métrica do professor" (fórmula a definir): escondida até ligar.
    // A conta fica em LeituraCampanhasMeta::metricaDoProfessor().
    'metrica_professor' => (bool) env('LEITURA_META_METRICA_PROFESSOR', false),

    // Insights guardados por alguns minutos (limite de chamadas da Graph API).
    'cache_minutos' => 5,

    // Insights diários (gasto desde a última venda): no máximo esta janela.
    'dias_diarios_max' => 90,
];
