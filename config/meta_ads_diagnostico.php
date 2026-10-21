<?php

/*
 * Meta ADS: "O que fazer agora" (pedido do Lira 2026-10-21, proposta do
 * Jonathan em Documents/2026-10-21-meta-ads-cta-e-diagnostico-proposta.md).
 * Todos os limites das regras de App\Services\Meta\MetaAdsDiagnostico ficam
 * aqui, num lugar só. Valores em reais; CTR em %.
 */

return [
    // Escopo com menos que isso (gasto E impressões) = "Ainda é cedo".
    // Anúncio abaixo dos dois limites fica fora das regras de resultado.
    'minimo_gasto' => 30.0,
    'minimo_impressoes' => 1000,

    // R1, R7, R8, R9: gasto mínimo para julgar venda e ROAS.
    'gasto_para_julgar' => 50.0,

    // R2: cliques no link sem nenhuma compra.
    'cliques_sem_compra' => 50,
    // R2 por funil: visualização da página abaixo disso (% dos cliques) = página lenta.
    'pagina_carregou_min' => 0.60,

    // R3: impressões mínimas e CTR do link (%) abaixo do qual "pouca gente clica".
    'impressoes_para_ctr' => 1000,
    'ctr_link_min' => 1.0,

    // R4: cliques mínimos; CPC do link acima de N vezes a média da conta, ou
    // acima do valor fixo quando a conta ainda não tem média.
    'cliques_para_cpc' => 30,
    'cpc_vezes_media' => 2.0,
    'cpc_teto_sem_historico' => 1.50,

    // R6: frequência do conjunto acima disso ("as mesmas pessoas já viram").
    // A proposta também pedia "> 3 com CTR em queda nos 7 dias": a queda
    // precisaria da série diária e ficou de fora por enquanto.
    'frequencia_max' => 4.0,

    // R9: ROAS N vezes acima do mínimo e compras mínimas para sugerir mais verba.
    'roas_oportunidade_vezes' => 1.3,
    'compras_oportunidade' => 3,

    // R10: anúncio ativo há mais que isso (horas) sem nenhuma impressão.
    'horas_sem_impressao' => 24,

    // R13: anúncio com essa fatia do gasto do conjunto e irmão com mais
    // compras e ROAS melhor.
    'fatia_do_gasto' => 0.70,
    'compras_irmao' => 2,

    // Cartões por anúncio na lista principal (o resto vai para "Ver todas").
    'cartoes_por_alvo' => 2,

    /*
     * ROAS mínimo para não perder dinheiro = 1 ÷ margem antes do ADS, pela
     * conta oficial (ContributionMargin::porItem, canal do site, que é para
     * onde os anúncios da Meta levam):
     *   1. margem dos produtos da campanha no site, nos últimos N dias, se
     *      tiver pelo menos 'pedidos_minimos' pedidos;
     *   2. senão, margem do site inteiro no mesmo período, com o mesmo mínimo;
     *   3. senão, o valor fixo abaixo.
     * Margem abaixo de 'margem_minima_confiavel' (ex.: custo faltando no
     * cadastro) não é usada: cai para o passo seguinte.
     *
     * Valor fixo 3,0: em out/2026 o site teve só 2 pedidos em 30 dias, com
     * margem antes do ADS de ~33% (1 ÷ 0,33 ≈ 3,0). Revisar quando o site
     * tiver volume e a conta passar a usar a margem real sozinha.
     */
    'roas_minimo_dias' => 30,
    'pedidos_minimos' => 5,
    'margem_minima_confiavel' => 0.05,
    'roas_minimo_padrao' => 3.0,
];
