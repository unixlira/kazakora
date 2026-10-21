<?php

namespace Tests\Unit\Meta;

use App\Services\Meta\MetaAdsDiagnostico;
use App\Services\Meta\MetaAdsService;
use PHPUnit\Framework\TestCase;

/**
 * Regras do "O que fazer agora" (2026-10-21). Classe pura: espelho entra,
 * cartões saem. Limites padrão de config/meta_ads_diagnostico.php.
 */
class MetaAdsDiagnosticoTest extends TestCase
{
    private const AGORA = '2026-10-21 12:00:00';

    /** @param  array<string, mixed>  $m */
    private function metricas(array $m = []): array
    {
        return $m + [
            'gasto' => 0.0, 'impressoes' => 0, 'alcance' => 0, 'cliques' => 0, 'cpc' => null, 'compras' => 0, 'vendas' => 0.0, 'roas' => null,
            'frequencia' => null, 'cpm' => null, 'cliques_link' => 0, 'ctr_link' => null, 'cpc_link' => null,
            'visualizacoes_pagina' => 0, 'carrinho' => 0, 'checkout' => 0,
        ];
    }

    private function anuncio(string $id, array $m = [], array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'nome' => 'Anúncio '.$id, 'status' => 'ACTIVE', 'miniatura' => null,
            'criado_em' => '2026-10-15T10:00:00-03:00', 'gerenciador' => 'https://adsmanager.facebook.com/x',
            'cta' => MetaAdsService::ctaDoCriativo(['call_to_action_type' => 'SHOP_NOW'], 'OUTCOME_SALES'),
            'metricas' => $this->metricas($m),
        ];
    }

    private function campanha(array $anuncios, array $conjunto = [], string $objetivo = 'OUTCOME_SALES'): array
    {
        $gasto = array_sum(array_map(fn ($a) => $a['metricas']['gasto'], $anuncios));
        $impressoes = array_sum(array_map(fn ($a) => $a['metricas']['impressoes'], $anuncios));

        return [
            'id' => 'c1', 'nome' => 'Campanha', 'status' => 'ACTIVE', 'objetivo' => 'Vendas', 'objetivo_codigo' => $objetivo,
            'metricas' => $this->metricas(['gasto' => $gasto, 'impressoes' => $impressoes]),
            'conjuntos' => [$conjunto + [
                'id' => 's1', 'nome' => 'Conjunto', 'status' => 'ACTIVE', 'aprendizado' => null, 'gerenciador_conjunto' => null,
                'metricas' => $this->metricas(['gasto' => $gasto, 'impressoes' => $impressoes]),
                'anuncios' => $anuncios,
            ]],
        ];
    }

    private function analisar(array $campanhas, array $contexto = []): array
    {
        return (new MetaAdsDiagnostico)->analisar($campanhas, $contexto + [
            'periodo' => 'last_7d',
            'agora' => self::AGORA,
            'roas' => ['padrao' => ['valor' => 3.0, 'origem' => 'config', 'margem' => null], 'campanhas' => []],
        ]);
    }

    /** @return list<string> */
    private function regras(array $resultado): array
    {
        return array_column($resultado['cartoes'], 'regra');
    }

    public function test_r1_gastou_e_nao_vendeu(): void
    {
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 62.4, 'impressoes' => 1800, 'cliques_link' => 20, 'ctr_link' => 1.11])])]);

        $this->assertSame(['R1'], $this->regras($r));
        $this->assertSame(MetaAdsDiagnostico::URGENTE, $r['cartoes'][0]['prioridade']);
        $this->assertSame('R$ 62,40 gastos e nenhuma venda', $r['cartoes'][0]['titulo']);
        $this->assertSame('anuncio', $r['cartoes'][0]['alvo']['tipo']);
        $this->assertTrue($r['cartoes'][0]['pausar'], 'sugestão de desempenho ganha o Pausar criativo');
    }

    public function test_r1_nao_vale_para_campanha_de_trafego(): void
    {
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 62.4, 'impressoes' => 1800, 'cliques_link' => 20, 'ctr_link' => 1.11])], [], 'OUTCOME_TRAFFIC')]);

        $this->assertSame([], $this->regras($r));
        $this->assertSame('vazio', $r['estado']);
    }

    public function test_r2_mostra_onde_o_funil_para(): void
    {
        $base = ['gasto' => 40.0, 'impressoes' => 3000, 'cliques_link' => 60, 'ctr_link' => 2.0, 'cpc_link' => 0.67];
        $casos = [
            [['visualizacoes_pagina' => 20], 'A página demora para abrir'],
            [['visualizacoes_pagina' => 50], 'Quem chega não coloca no carrinho'],
            [['visualizacoes_pagina' => 50, 'carrinho' => 4, 'checkout' => 2], 'Desistem no checkout'],
            [[], 'Revise a página do produto: preço, frete e fotos.'],
        ];

        foreach ($casos as [$funil, $esperado]) {
            $r = $this->analisar([$this->campanha([$this->anuncio('a1', $funil + $base)])]);
            $this->assertSame(['R2'], $this->regras($r));
            $this->assertStringContainsString($esperado, $r['cartoes'][0]['acao']);
            $this->assertSame('60 pessoas clicaram e ninguém comprou', $r['cartoes'][0]['titulo']);
        }
    }

    public function test_r3_ctr_baixo(): void
    {
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 20.0, 'impressoes' => 2500, 'cliques_link' => 15, 'ctr_link' => 0.6])], [], 'OUTCOME_TRAFFIC')]);

        $this->assertSame(['R3'], $this->regras($r));
        $this->assertSame('Pouca gente clica: CTR de 0,60%', $r['cartoes'][0]['titulo']);
    }

    public function test_r4_clique_caro_pela_media_da_conta_e_sem_historico(): void
    {
        $anuncio = $this->anuncio('a1', ['gasto' => 45.0, 'impressoes' => 2000, 'cliques_link' => 30, 'ctr_link' => 1.5, 'cpc_link' => 1.5]);

        $r = $this->analisar([$this->campanha([$anuncio], [], 'OUTCOME_TRAFFIC')], ['cpc_conta' => 0.5]);
        $this->assertSame(['R4'], $this->regras($r));
        $this->assertStringContainsString('3,0× mais caro que a média da conta', $r['cartoes'][0]['acao']);

        // Sem média da conta: teto fixo de R$ 1,50 (1,50 não passa).
        $this->assertSame([], $this->regras($this->analisar([$this->campanha([$anuncio], [], 'OUTCOME_TRAFFIC')])));
    }

    public function test_r5_botao_de_pouca_intencao_em_campanha_de_vendas(): void
    {
        $cta = MetaAdsService::ctaDoCriativo(['object_story_spec' => ['video_data' => ['call_to_action' => ['type' => 'LEARN_MORE']]]], 'OUTCOME_SALES');
        $this->assertTrue($cta['desalinhado']);
        $this->assertSame('Saiba mais', $cta['rotulos']['LEARN_MORE']);

        // Pouco dado: Aviso.
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 5.0, 'impressoes' => 300], ['cta' => $cta])])]);
        $this->assertSame(['R5'], $this->regras($r));
        $this->assertSame(MetaAdsDiagnostico::AVISO, $r['cartoes'][0]['prioridade']);
        $this->assertSame('Campanha de vendas com botão "Saiba mais"', $r['cartoes'][0]['titulo']);
        $this->assertFalse($r['cartoes'][0]['pausar'], 'botão de CTA se resolve duplicando, não pausando');

        // ROAS abaixo do mínimo: Atenção.
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 40.0, 'impressoes' => 2000, 'compras' => 1, 'vendas' => 80.0, 'roas' => 2.0, 'ctr_link' => 2.0], ['cta' => $cta])])]);
        $this->assertSame(MetaAdsDiagnostico::ATENCAO, $r['cartoes'][0]['prioridade']);
    }

    public function test_cta_dinamico_sem_botao_e_desconhecido(): void
    {
        $dinamico = MetaAdsService::ctaDoCriativo(['asset_feed_spec' => ['call_to_action_types' => ['SHOP_NOW', 'LEARN_MORE']]], 'OUTCOME_SALES');
        $this->assertTrue($dinamico['dinamico']);
        $this->assertSame(['SHOP_NOW', 'LEARN_MORE'], $dinamico['codigos']);
        $this->assertFalse($dinamico['desalinhado'], 'tem botão de compra entre as opções');

        $semBotao = MetaAdsService::ctaDoCriativo(['object_story_spec' => ['link_data' => []]], 'OUTCOME_SALES');
        $this->assertTrue($semBotao['sem_botao']);
        $this->assertTrue($semBotao['desalinhado']);

        $catalogo = MetaAdsService::ctaDoCriativo(['call_to_action_type' => 'SHOP_NOW', 'product_set_id' => '99'], 'OUTCOME_SALES');
        $this->assertTrue($catalogo['dinamico']);

        $estranho = MetaAdsService::ctaDoCriativo(['call_to_action_type' => 'BOOK_TRAVEL'], 'OUTCOME_SALES');
        $this->assertNull($estranho['rotulos']['BOOK_TRAVEL']);
        $this->assertFalse($estranho['desalinhado']);

        $this->assertNull(MetaAdsService::ctaDoCriativo(null));
        $this->assertFalse(MetaAdsService::ctaDoCriativo(['call_to_action_type' => 'LEARN_MORE'], 'OUTCOME_TRAFFIC')['desalinhado']);
    }

    public function test_r6_frequencia_alta_no_conjunto(): void
    {
        $campanha = $this->campanha([$this->anuncio('a1', ['gasto' => 20.0, 'impressoes' => 1500, 'ctr_link' => 2.0])], [], 'OUTCOME_TRAFFIC');
        $campanha['conjuntos'][0]['metricas']['frequencia'] = 4.6;

        $r = $this->analisar([$campanha]);
        $this->assertSame(['R6'], $this->regras($r));
        $this->assertSame('conjunto', $r['cartoes'][0]['alvo']['tipo']);
        $this->assertSame('As mesmas pessoas já viram 4,6 vezes', $r['cartoes'][0]['titulo']);
    }

    public function test_r7_vendeu_menos_do_que_gastou_aponta_o_melhor(): void
    {
        $r = $this->analisar([$this->campanha([
            $this->anuncio('a1', ['gasto' => 80.0, 'impressoes' => 4000, 'compras' => 1, 'vendas' => 60.0, 'roas' => 0.75, 'ctr_link' => 2.0]),
            $this->anuncio('a2', ['gasto' => 20.0, 'impressoes' => 900, 'compras' => 1, 'vendas' => 100.0, 'roas' => 5.0, 'ctr_link' => 2.0]),
        ])]);

        $r7 = collect($r['cartoes'])->firstWhere('regra', 'R7');
        $this->assertSame('Vendeu menos do que gastou: ROAS 0,75', $r7['titulo']);
        $this->assertStringContainsString('(Anúncio a2)', $r7['acao']);
    }

    public function test_r8_abaixo_do_minimo_usa_a_meta_da_campanha(): void
    {
        $anuncio = $this->anuncio('a1', ['gasto' => 100.0, 'impressoes' => 4000, 'compras' => 2, 'vendas' => 250.0, 'roas' => 2.5, 'ctr_link' => 2.0]);

        $r = $this->analisar([$this->campanha([$anuncio])]);
        $this->assertSame(['R8'], $this->regras($r));
        $this->assertSame('ROAS 2,50, abaixo do mínimo de 3,00', $r['cartoes'][0]['titulo']);

        // Margem dos produtos da campanha de 50% => mínimo 2,0: não dispara.
        $r = $this->analisar([$this->campanha([$anuncio])], ['roas' => [
            'padrao' => ['valor' => 3.0, 'origem' => 'config', 'margem' => null],
            'campanhas' => ['c1' => ['valor' => 2.0, 'origem' => 'produtos', 'margem' => 0.5]],
        ]]);
        $this->assertSame([], $this->regras($r));
    }

    public function test_r9_oportunidade_fora_do_aprendizado(): void
    {
        $anuncio = $this->anuncio('a1', ['gasto' => 100.0, 'impressoes' => 4000, 'compras' => 4, 'vendas' => 450.0, 'roas' => 4.5, 'ctr_link' => 2.0]);

        $r = $this->analisar([$this->campanha([$anuncio])]);
        $this->assertSame(['R9'], $this->regras($r));
        $this->assertSame(MetaAdsDiagnostico::OPORTUNIDADE, $r['cartoes'][0]['prioridade']);
        $this->assertFalse($r['cartoes'][0]['pausar'], 'anúncio vendendo bem não ganha Pausar');

        $r = $this->analisar([$this->campanha([$anuncio], ['aprendizado' => 'LEARNING'])]);
        $this->assertSame(['R11'], $this->regras($r), 'em aprendizado: só o aviso de não mexer');
    }

    public function test_r10_ativo_sem_impressao_ha_mais_de_um_dia(): void
    {
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', [], ['criado_em' => '2026-10-19T10:00:00-03:00'])])]);
        $this->assertSame(['R10'], $this->regras($r));
        $this->assertSame(0, $r['sem_dados']);

        // Criado há 3 horas: ainda cedo.
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', [], ['criado_em' => '2026-10-21T09:00:00-03:00'])])]);
        $this->assertSame([], $this->regras($r));
        $this->assertSame('cedo', $r['estado']);
    }

    public function test_r12_aprendizado_limitado(): void
    {
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 10.0, 'impressoes' => 500])], ['aprendizado' => 'FAIL'])]);
        $this->assertSame(['R12'], $this->regras($r));
    }

    public function test_r13_verba_no_anuncio_mais_fraco(): void
    {
        $r = $this->analisar([$this->campanha([
            $this->anuncio('a1', ['gasto' => 45.0, 'impressoes' => 3000, 'compras' => 1, 'vendas' => 90.0, 'roas' => 2.0, 'ctr_link' => 2.0]),
            $this->anuncio('a2', ['gasto' => 10.0, 'impressoes' => 600, 'compras' => 2, 'vendas' => 80.0, 'roas' => 8.0, 'ctr_link' => 2.0]),
        ])]);

        $r13 = collect($r['cartoes'])->firstWhere('regra', 'R13');
        $this->assertSame('a1', $r13['alvo']['id']);
        $this->assertStringContainsString('Pause "Anúncio a1" para a verba ir para "Anúncio a2", que vende com ROAS 8,00.', $r13['acao']);
    }

    public function test_pausado_fica_de_fora(): void
    {
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 90.0, 'impressoes' => 3000], ['status' => 'PAUSED'])])]);

        $this->assertSame([], $this->regras($r));
        $this->assertSame('vazio', $r['estado']);
    }

    public function test_ordem_e_no_maximo_dois_cartoes_por_anuncio_na_frente(): void
    {
        $cta = MetaAdsService::ctaDoCriativo(['call_to_action_type' => 'LEARN_MORE'], 'OUTCOME_SALES');
        $r = $this->analisar([$this->campanha([
            // R1, R2, R3 e R5 no mesmo anúncio.
            $this->anuncio('a1', ['gasto' => 70.0, 'impressoes' => 9000, 'cliques_link' => 60, 'ctr_link' => 0.67, 'cpc_link' => 1.17], ['cta' => $cta]),
            $this->anuncio('a2', ['gasto' => 200.0, 'impressoes' => 9000, 'compras' => 1, 'vendas' => 100.0, 'roas' => 0.5, 'cliques_link' => 20, 'ctr_link' => 2.0]),
        ])]);

        $frente = array_values(array_filter($r['cartoes'], fn ($c) => empty($c['repetido'])));
        // Urgentes primeiro, do maior gasto para o menor.
        $this->assertSame('R7', $frente[0]['regra']);
        $this->assertSame(['a2', 'a1', 'a1'], array_map(fn ($c) => $c['alvo']['id'], array_slice($frente, 0, 3)));
        $this->assertSame(2, collect($frente)->where('alvo.id', 'a1')->count());
        $this->assertSame(5, $r['total']);
        $this->assertTrue(end($r['cartoes'])['repetido']);
    }

    public function test_pouco_dado_no_escopo(): void
    {
        $r = $this->analisar([$this->campanha([$this->anuncio('a1', ['gasto' => 12.0, 'impressoes' => 400])])]);

        $this->assertSame('cedo', $r['estado']);
        $this->assertSame(1, $r['sem_dados']);
        $this->assertSame(['gasto' => 12.0, 'impressoes' => 400], $r['escopo']);
        $this->assertTrue($this->analisar([], ['periodo' => 'maximum'])['periodo_longo']);
    }
}
