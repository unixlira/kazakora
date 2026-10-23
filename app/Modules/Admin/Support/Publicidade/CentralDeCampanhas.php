<?php

namespace App\Modules\Admin\Support\Publicidade;

/**
 * Central de campanhas (pedido do Lira em 2026-10-23; texto e UX do
 * Jonathan): Meta ADS e Google ADS numa tela só, só leitura.
 *
 * Aqui ficam as regras que não dependem da plataforma: períodos, status
 * comum, formato da linha, totais e os alertas genéricos. ROAS é o da
 * plataforma (venda atribuída ÷ gasto); lucro é sempre ContributionMargin.
 * Nada daqui grava em channel_ad_spends.
 */
final class CentralDeCampanhas
{
    /** Fontes na ordem da tela. Google entra com dados quando a fonte dele ler a API. */
    public const FONTES = [
        PlataformaDeAnuncio::META => CampanhasMeta::class,
        PlataformaDeAnuncio::GOOGLE => CampanhasGoogle::class,
    ];

    public const PERIODOS = [
        'hoje' => 'Hoje',
        '7d' => 'Últimos 7 dias',
        '30d' => 'Últimos 30 dias',
        'mes' => 'Mês atual',
    ];

    public const PERIODO_PADRAO = '7d';

    public const ATIVA = 'ativa';

    public const PAUSADA = 'pausada';

    public const EM_REVISAO = 'em_revisao';

    public const COM_PROBLEMA = 'com_problema';

    public const ENCERRADA = 'encerrada';

    /** Status comum => rótulo (ordem da tela e do filtro). */
    public const STATUS = [
        self::ATIVA => 'Ativa',
        self::EM_REVISAO => 'Em revisão',
        self::COM_PROBLEMA => 'Com problema',
        self::PAUSADA => 'Pausada',
        self::ENCERRADA => 'Encerrada',
    ];

    /** Gasto mínimo para o alerta "gastou sem venda" (o mesmo piso do diagnóstico da Meta). */
    public const GASTO_SEM_VENDA = 30.0;

    public static function periodo(?string $periodo): string
    {
        return array_key_exists((string) $periodo, self::PERIODOS) ? (string) $periodo : self::PERIODO_PADRAO;
    }

    /**
     * Linha comum da lista. As métricas derivadas (CTR, CPC, custo por
     * resultado, ROAS) saem daqui, iguais para todas as plataformas.
     *
     * @param  array{id: string, nome: string, status: string, status_plataforma?: ?string, objetivo?: ?string, busca_venda?: bool, orcamento_diario?: ?float, gasto?: float, impressoes?: int, cliques?: int, resultados?: int, vendas?: float, gerenciador?: ?string, detalhe?: ?string}  $dados
     * @return array<string, mixed>
     */
    public static function linha(string $canal, array $dados): array
    {
        $gasto = round((float) ($dados['gasto'] ?? 0), 2);
        $impressoes = (int) ($dados['impressoes'] ?? 0);
        $cliques = (int) ($dados['cliques'] ?? 0);
        $resultados = (int) ($dados['resultados'] ?? 0);
        $vendas = round((float) ($dados['vendas'] ?? 0), 2);
        $status = array_key_exists($dados['status'], self::STATUS) ? $dados['status'] : self::PAUSADA;

        return [
            'chave' => $canal.':'.$dados['id'],
            'plataforma' => $canal,
            'id' => (string) $dados['id'],
            'nome' => (string) $dados['nome'],
            'status' => $status,
            'status_rotulo' => self::STATUS[$status],
            'status_plataforma' => $dados['status_plataforma'] ?? null,
            'objetivo' => $dados['objetivo'] ?? null,
            'busca_venda' => (bool) ($dados['busca_venda'] ?? true),
            'orcamento_diario' => isset($dados['orcamento_diario']) && $dados['orcamento_diario'] > 0 ? round((float) $dados['orcamento_diario'], 2) : null,
            'gasto' => $gasto,
            'impressoes' => $impressoes,
            'cliques' => $cliques,
            'ctr' => $impressoes > 0 ? round($cliques / $impressoes * 100, 2) : null,
            'cpc' => $cliques > 0 ? round($gasto / $cliques, 2) : null,
            'resultados' => $resultados,
            'custo_por_resultado' => $resultados > 0 ? round($gasto / $resultados, 2) : null,
            'vendas' => $vendas,
            'roas' => $gasto > 0 ? round($vendas / $gasto, 2) : null,
            'gerenciador' => $dados['gerenciador'] ?? null,
            'detalhe' => $dados['detalhe'] ?? null,
        ];
    }

    /**
     * Totais de uma plataforma (ou de todas) a partir das linhas.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array{campanhas: int, ativas: int, gasto: float, impressoes: int, cliques: int, resultados: int, vendas: float, roas: ?float, cpc: ?float}
     */
    public static function totais(array $linhas): array
    {
        $soma = fn (string $campo) => array_sum(array_map(fn ($l) => $l[$campo], $linhas));
        $gasto = round((float) $soma('gasto'), 2);
        $vendas = round((float) $soma('vendas'), 2);
        $cliques = (int) $soma('cliques');

        return [
            'campanhas' => count($linhas),
            'ativas' => count(array_filter($linhas, fn ($l) => $l['status'] === self::ATIVA)),
            'gasto' => $gasto,
            'impressoes' => (int) $soma('impressoes'),
            'cliques' => $cliques,
            'resultados' => (int) $soma('resultados'),
            'vendas' => $vendas,
            'roas' => $gasto > 0 ? round($vendas / $gasto, 2) : null,
            'cpc' => $cliques > 0 ? round($gasto / $cliques, 2) : null,
        ];
    }

    /**
     * Alertas que valem para qualquer plataforma, tirados da própria lista:
     * campanha com problema e campanha de venda que gastou sem vender.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return list<array{prioridade: string, titulo: string, alvo: string, acao: string, prova: string, link: ?string}>
     */
    public static function alertasDaLista(array $linhas, string $periodo): array
    {
        $quando = ['hoje' => 'hoje', '7d' => 'nos últimos 7 dias', '30d' => 'nos últimos 30 dias', 'mes' => 'neste mês'][$periodo] ?? '';
        $alertas = [];

        foreach ($linhas as $l) {
            if ($l['status'] === self::COM_PROBLEMA) {
                $alertas[] = [
                    'prioridade' => 'urgente',
                    'titulo' => 'Campanha com problema na plataforma',
                    'alvo' => $l['nome'],
                    'acao' => 'Abra no Gerenciador para ver o motivo: anúncio reprovado ou pagamento pendente param a entrega.',
                    'prova' => 'Status: '.($l['status_plataforma'] ?? $l['status_rotulo']),
                    'link' => $l['gerenciador'],
                    'gasto' => $l['gasto'],
                ];
            } elseif ($l['status'] === self::ATIVA && $l['busca_venda'] && $l['resultados'] === 0 && $l['gasto'] >= self::GASTO_SEM_VENDA) {
                $alertas[] = [
                    'prioridade' => 'urgente',
                    'titulo' => 'Gastou '.self::reais($l['gasto']).' sem venda '.$quando,
                    'alvo' => $l['nome'],
                    'acao' => 'Revise o criativo e a página do produto antes de manter o orçamento.',
                    'prova' => self::reais($l['gasto']).' gastos · '.number_format($l['cliques'], 0, ',', '.').' cliques · 0 compras',
                    'link' => $l['gerenciador'],
                    'gasto' => $l['gasto'],
                ];
            }
        }

        // Problema primeiro (a entrega parou), depois o maior gasto.
        usort($alertas, fn ($a, $b) => [str_starts_with($b['titulo'], 'Campanha com problema'), $b['gasto']] <=> [str_starts_with($a['titulo'], 'Campanha com problema'), $a['gasto']]);

        return array_map(fn ($a) => array_diff_key($a, ['gasto' => true]), $alertas);
    }

    public static function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
