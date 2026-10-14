<?php

namespace App\Modules\Admin\Support\Indicadores;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\Diagnostico\EstrategiaShopeeAds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Indicadores com alerta (pedido do Lira, 2026-10-14): as métricas mais
 * usadas no e-commerce, por canal, comparando os últimos N dias COMPLETOS
 * (até ontem) com os N dias anteriores. Dia de hoje fica de fora para a
 * comparação não acusar queda falsa por dia pela metade.
 *
 * Nada de conta nova: margem é ContributionMargin::porCanal(), ADS é
 * channel_ad_spends (o mesmo do Financeiro) e visitante é a regra do painel
 * (SiteVisit::visitantesPorDia + soDeFora). Os limites estão nas constantes
 * abaixo e explicados em docs/indicadores-e-alertas.md.
 */
class PainelDeAlertas
{
    public const CRITICO = 'critico';

    public const ATENCAO = 'atencao';

    public const CANAIS = [
        Order::ORIGIN_STORE => 'KazaKora (site)',
        Order::ORIGIN_SHOPEE => 'Shopee',
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_AMAZON => 'Amazon',
    ];

    /** Canais em que o status do pedido acompanha o envio (TikTok e Amazon vêm do Bling e ficam "pago"). */
    private const CANAIS_COM_ENVIO = [Order::ORIGIN_STORE, Order::ORIGIN_SHOPEE, Order::ORIGIN_MERCADO_LIVRE];

    /** Queda de faturamento: % (atenção, crítico) e mínimo no período anterior para valer. */
    private const QUEDA_FATURAMENTO = [20, 40];

    private const FATURAMENTO_MINIMO = 300.0;

    private const QUEDA_TICKET = 15;

    /** Margem de contribuição sobre a receita: abaixo disso é atenção; negativa é crítico. */
    private const MARGEM_MINIMA = 10;

    /** Pedidos cancelados (inclui comprador e falta de pagamento): % atenção, crítico. */
    private const CANCELAMENTO = [8, 15];

    /** Devolução + reclamação abertas no período sobre os pedidos: % atenção, crítico. */
    private const DEVOLUCAO = [3, 6];

    private const PEDIDOS_MINIMOS = 10;

    /** ADS: gasto mínimo no período para tirar conclusão. */
    private const ADS_GASTO_MINIMO = 50.0;

    private const QUEDA_ROAS = 20;

    /** TACOS (ADS sobre o faturamento total do canal): % atenção, crítico. */
    private const TACOS = [12, 20];

    /** CTR mínimo por canal (Shopee: regra do Cauê, 2%). */
    private const CTR_MINIMO = [Order::ORIGIN_SHOPEE => 2.0, Order::ORIGIN_MERCADO_LIVRE => 0.6];

    private const QUEDA_CTR = 20;

    private const ALTA_CPC = 25;

    private const ALTA_CPA = 30;

    /** Conversão do clique no ADS em pedido (Shopee: referência 1,65% do Cauê). */
    private const CONVERSAO_ADS_MINIMA = [Order::ORIGIN_SHOPEE => 1.65];

    /** Site: visitantes únicos mínimos para falar de queda e de conversão. */
    private const VISITANTES_MINIMOS = 50;

    private const QUEDA_VISITANTES = 25;

    private const CONVERSAO_SITE_MINIMA = 0.5;

    /** Pedido pago sem sair para envio depois disso (horas). */
    private const HORAS_ENVIO = 48;

    /** Estoque que cobre menos dias de venda que isso vira alerta. */
    private const COBERTURA_DIAS = 7;

    public function __construct(
        private readonly ContributionMargin $margem,
        private readonly EstrategiaShopeeAds $estrategia,
    ) {}

    public function gerar(int $dias = 7): array
    {
        $dias = in_array($dias, [7, 14, 30], true) ? $dias : 7;
        $ate = Carbon::today();
        $desde = $ate->copy()->subDays($dias);
        $antes = $desde->copy()->subDays($dias);

        $atual = $this->vendas($desde, $ate);
        $anterior = $this->vendas($antes, $desde);
        $adsAtual = $this->ads($desde, $ate);
        $adsAnterior = $this->ads($antes, $desde);

        $alertas = [];
        $canais = [];
        foreach (self::CANAIS as $canal => $nome) {
            $a = $atual[$canal] ?? null;
            $p = $anterior[$canal] ?? null;
            $metricas = $this->metricasDeVenda($canal, $a, $p, $alertas);
            if (isset($adsAtual[$canal]) || isset($adsAnterior[$canal])) {
                $metricas = array_merge($metricas, $this->metricasDeAds($canal, $adsAtual[$canal] ?? null, $adsAnterior[$canal] ?? null, $a, $alertas));
            }
            if ($canal === Order::ORIGIN_STORE) {
                $metricas = array_merge($metricas, $this->metricasDoSite($desde, $ate, $antes, $a, $p, $alertas));
            }
            if ($a === null && $p === null && ! isset($adsAtual[$canal])) {
                continue;
            }
            $canais[] = ['canal' => $canal, 'nome' => $nome, 'metricas' => $metricas];
        }

        $this->outrosSites($desde, $ate, $antes, $alertas, $canais);
        $this->shopeeCampanhas($desde, $ate, $alertas);
        $this->operacao($alertas);

        usort($alertas, fn ($x, $y) => [$x['nivel'] === self::CRITICO ? 0 : 1, -($x['peso'] ?? 0)] <=> [$y['nivel'] === self::CRITICO ? 0 : 1, -($y['peso'] ?? 0)]);

        return [
            'dias' => $dias,
            'desde' => $desde->toDateString(),
            'ate' => $ate->copy()->subDay()->toDateString(),
            'anterior_desde' => $antes->toDateString(),
            'anterior_ate' => $desde->copy()->subDay()->toDateString(),
            'gerado_em' => now()->toIso8601String(),
            'resumo' => [
                'critico' => count(array_filter($alertas, fn ($x) => $x['nivel'] === self::CRITICO)),
                'atencao' => count(array_filter($alertas, fn ($x) => $x['nivel'] === self::ATENCAO)),
            ],
            'alertas' => array_map(fn ($x) => array_diff_key($x, ['peso' => 0]), $alertas),
            'canais' => $canais,
        ];
    }

    /** Pedidos, receita e margem por canal (ContributionMargin), mais cancelados e devoluções. */
    private function vendas(Carbon $desde, Carbon $ate): array
    {
        $linhas = $this->margem->porCanal($desde, $ate);

        $cancelados = Order::query()->vendaEfetiva()
            ->whereIn('origin', array_keys(self::CANAIS))
            ->where('created_at', '>=', $desde)->where('created_at', '<', $ate)
            ->selectRaw("origin, COUNT(*) as total, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelados")
            ->groupBy('origin')->get()->keyBy('origin');

        $devolucoes = DB::table('marketplace_returns')
            ->where('opened_at', '>=', $desde)->where('opened_at', '<', $ate)
            ->selectRaw('channel, COUNT(*) as total')->groupBy('channel')->pluck('total', 'channel');

        $r = [];
        foreach (array_keys(self::CANAIS) as $canal) {
            $l = $linhas[$canal] ?? null;
            $c = $cancelados->get($canal);
            if (! $l && ! $c) {
                continue;
            }
            $r[$canal] = [
                'pedidos' => (int) ($l['pedidos'] ?? 0),
                'receita' => (float) ($l['receita'] ?? 0),
                'margem' => (float) ($l['margem'] ?? 0),
                'ads' => (float) ($l['ads'] ?? 0),
                'todos' => (int) ($c->total ?? 0),
                'cancelados' => (int) ($c->cancelados ?? 0),
                'devolucoes' => (int) ($devolucoes[$canal] ?? 0),
            ];
        }

        return $r;
    }

    private function ads(Carbon $desde, Carbon $ate): array
    {
        return ChannelAdSpend::query()
            ->where('date', '>=', $desde->toDateString())
            ->where('date', '<', $ate->toDateString())
            ->selectRaw('channel, SUM(impressions) as impressoes, SUM(clicks) as cliques, SUM(attributed_orders) as pedidos, SUM(attributed_gmv) as venda, SUM(spend) as gasto')
            ->groupBy('channel')->get()
            ->filter(fn ($l) => (float) $l->gasto > 0 || (int) $l->impressoes > 0)
            ->mapWithKeys(fn ($l) => [$l->channel => [
                'impressoes' => (int) $l->impressoes,
                'cliques' => (int) $l->cliques,
                'pedidos' => (int) $l->pedidos,
                'venda' => (float) $l->venda,
                'gasto' => (float) $l->gasto,
            ]])->all();
    }

    private function metricasDeVenda(string $canal, ?array $a, ?array $p, array &$alertas): array
    {
        $nome = self::CANAIS[$canal];
        $ticket = fn (?array $v) => $v && $v['pedidos'] > 0 ? $v['receita'] / $v['pedidos'] : null;
        $margemPct = fn (?array $v) => $v && $v['receita'] > 0 ? $v['margem'] / $v['receita'] * 100 : null;
        $cancel = fn (?array $v) => $v && $v['todos'] > 0 ? $v['cancelados'] / $v['todos'] * 100 : null;
        $devol = fn (?array $v) => $v && $v['pedidos'] > 0 ? $v['devolucoes'] / $v['pedidos'] * 100 : null;

        $m = [
            $this->metrica('faturamento', 'Faturamento', $a['receita'] ?? 0, $p['receita'] ?? null, 'moeda', 'alto'),
            $this->metrica('pedidos', 'Pedidos', $a['pedidos'] ?? 0, $p['pedidos'] ?? null, 'numero', 'alto'),
            $this->metrica('ticket', 'Ticket médio', $ticket($a), $ticket($p), 'moeda', 'alto'),
            $this->metrica('margem', 'Margem de contribuição', $a['margem'] ?? 0, $p['margem'] ?? null, 'moeda', 'alto'),
            $this->metrica('margem_pct', 'Margem sobre a venda', $margemPct($a), $margemPct($p), 'pct', 'alto'),
            $this->metrica('cancelamento', 'Cancelamento', $cancel($a), $cancel($p), 'pct', 'baixo'),
            $this->metrica('devolucao', 'Devolução/reclamação', $devol($a), $devol($p), 'pct', 'baixo'),
        ];
        $m = array_column($m, null, 'chave');

        // Faturamento.
        $var = $this->variacao($a['receita'] ?? 0, $p['receita'] ?? null);
        if (($p['receita'] ?? 0) >= self::FATURAMENTO_MINIMO && $var !== null && $var <= -self::QUEDA_FATURAMENTO[0]) {
            $nivel = $var <= -self::QUEDA_FATURAMENTO[1] ? self::CRITICO : self::ATENCAO;
            $alertas[] = $this->alerta($nivel, 'vendas', $canal, 'Faturamento', "{$nome}: faturamento caiu {$this->pct(-$var)} (R$ {$this->moeda($a['receita'] ?? 0)} contra R$ {$this->moeda($p['receita'])}).", 'Veja se algum anúncio campeão saiu do ar, perdeu estoque ou subiu de preço.', $p['receita'] - ($a['receita'] ?? 0));
            $m['faturamento']['nivel'] = $nivel;
        }

        // Ticket médio.
        $var = $this->variacao($ticket($a), $ticket($p));
        if (($a['pedidos'] ?? 0) >= self::PEDIDOS_MINIMOS && $var !== null && $var <= -self::QUEDA_TICKET) {
            $alertas[] = $this->alerta(self::ATENCAO, 'vendas', $canal, 'Ticket médio', "{$nome}: ticket médio caiu {$this->pct(-$var)} (R$ {$this->moeda($ticket($a))} contra R$ {$this->moeda($ticket($p))}).", 'Venda puxada por item barato: confira kit, desconto por quantidade e frete grátis mínimo.');
            $m['ticket']['nivel'] = self::ATENCAO;
        }

        // Margem de contribuição.
        if ($a && $a['receita'] > 0) {
            $pct = $margemPct($a);
            if ($a['margem'] < 0) {
                $alertas[] = $this->alerta(self::CRITICO, 'lucro', $canal, 'Margem de contribuição', "{$nome}: o canal deu prejuízo de R$ {$this->moeda(-$a['margem'])} no período (depois de custo, taxas, frete e ADS).", 'Corte o ADS que não se paga e reveja preço dos itens com prejuízo (Oportunidades › curva ABC).', -$a['margem'] * 2);
                $m['margem']['nivel'] = $m['margem_pct']['nivel'] = self::CRITICO;
            } elseif ($pct < self::MARGEM_MINIMA) {
                $alertas[] = $this->alerta(self::ATENCAO, 'lucro', $canal, 'Margem de contribuição', "{$nome}: sobra só {$this->pct($pct)} da venda (R$ {$this->moeda($a['margem'])}), abaixo dos ".self::MARGEM_MINIMA.'%.', 'Reveja preço e ADS dos itens que mais vendem nesse canal.');
                $m['margem_pct']['nivel'] = self::ATENCAO;
            }
        }

        // Cancelamento.
        $pct = $cancel($a);
        if (($a['todos'] ?? 0) >= self::PEDIDOS_MINIMOS && $pct !== null && $pct >= self::CANCELAMENTO[0]) {
            $nivel = $pct >= self::CANCELAMENTO[1] ? self::CRITICO : self::ATENCAO;
            $alertas[] = $this->alerta($nivel, 'operacao', $canal, 'Cancelamento', "{$nome}: {$this->pct($pct)} dos pedidos foram cancelados ({$a['cancelados']} de {$a['todos']}).", 'Inclui desistência do comprador e falta de pagamento. Cancelamento por falta de estoque ou atraso derruba a reputação: confira os motivos no canal.');
            $m['cancelamento']['nivel'] = $nivel;
        }

        // Devolução e reclamação.
        $pct = $devol($a);
        if (($a['pedidos'] ?? 0) >= self::PEDIDOS_MINIMOS && $pct !== null && $pct >= self::DEVOLUCAO[0]) {
            $nivel = $pct >= self::DEVOLUCAO[1] ? self::CRITICO : self::ATENCAO;
            $alertas[] = $this->alerta($nivel, 'operacao', $canal, 'Devolução/reclamação', "{$nome}: {$a['devolucoes']} devoluções ou reclamações abertas para {$a['pedidos']} pedidos ({$this->pct($pct)}).", 'Veja em Devoluções qual produto repete o motivo (defeito, descrição, embalagem).', 0, '/admin/devolucoes');
            $m['devolucao']['nivel'] = $nivel;
        }

        return array_values($m);
    }

    private function metricasDeAds(string $canal, ?array $a, ?array $p, ?array $vendas, array &$alertas): array
    {
        $nome = self::CANAIS[$canal];
        $roas = fn (?array $v) => $v && $v['gasto'] > 0 && $v['venda'] > 0 ? $v['venda'] / $v['gasto'] : null;
        $ctr = fn (?array $v) => $v && $v['impressoes'] > 0 ? $v['cliques'] / $v['impressoes'] * 100 : null;
        $cpc = fn (?array $v) => $v && $v['cliques'] > 0 ? $v['gasto'] / $v['cliques'] : null;
        $cpa = fn (?array $v) => $v && $v['pedidos'] > 0 ? $v['gasto'] / $v['pedidos'] : null;
        $conv = fn (?array $v) => $v && $v['cliques'] > 0 ? $v['pedidos'] / $v['cliques'] * 100 : null;
        $acos = fn (?array $v) => ($r = $roas($v)) ? 100 / $r : null;
        $tacos = $vendas && $vendas['receita'] > 0 && $a ? $a['gasto'] / $vendas['receita'] * 100 : null;
        // Empate do canal: receita ÷ margem antes do ADS (abaixo disso o ADS come o lucro).
        $antesDoAds = $vendas ? $vendas['margem'] + $vendas['ads'] : null;
        $empate = $vendas && $antesDoAds > 0 && $vendas['receita'] > 0 ? $vendas['receita'] / $antesDoAds : null;
        $lucroPorPedido = $vendas && $vendas['pedidos'] > 0 && $antesDoAds !== null ? $antesDoAds / $vendas['pedidos'] : null;

        $m = array_column([
            $this->metrica('ads_gasto', 'Investimento em ADS', $a['gasto'] ?? 0, $p['gasto'] ?? null, 'moeda', 'neutro'),
            $this->metrica('roas', 'ROAS', $roas($a), $roas($p), 'vezes', 'alto', $empate ? 'empate '.$this->um($empate) : null),
            $this->metrica('acos', 'ACOS', $acos($a), $acos($p), 'pct', 'baixo'),
            $this->metrica('tacos', 'TACOS', $tacos, null, 'pct', 'baixo'),
            $this->metrica('ctr', 'CTR', $ctr($a), $ctr($p), 'pct', 'alto', isset(self::CTR_MINIMO[$canal]) ? 'mín. '.$this->pct(self::CTR_MINIMO[$canal]) : null),
            $this->metrica('cpc', 'CPC', $cpc($a), $cpc($p), 'moeda', 'baixo'),
            $this->metrica('cpa', 'CPA (custo por pedido)', $cpa($a), $cpa($p), 'moeda', 'baixo', $lucroPorPedido > 0 ? 'lucro/pedido R$ '.$this->moeda($lucroPorPedido) : null),
            $this->metrica('conversao_ads', 'Conversão do ADS', $conv($a), $conv($p), 'pct', 'alto', isset(self::CONVERSAO_ADS_MINIMA[$canal]) ? 'mín. '.$this->pct(self::CONVERSAO_ADS_MINIMA[$canal]) : null),
        ], null, 'chave');

        if (! $a || $a['gasto'] < self::ADS_GASTO_MINIMO) {
            return array_values($m);
        }
        $link = $canal === Order::ORIGIN_SHOPEE ? '/admin/publicidade/shopee' : '/admin/publicidade';

        $r = $roas($a);
        if ($r !== null && $empate !== null && $r < $empate) {
            $alertas[] = $this->alerta(self::CRITICO, 'ads', $canal, 'ROAS', "{$nome} ADS: ROAS {$this->um($r)} abaixo do empate do canal ({$this->um($empate)}). Cada R$ 1 de ADS está tirando lucro.", 'Pause as campanhas que gastam sem vender e suba a meta de ROAS das outras.', $a['gasto'], $link);
            $m['roas']['nivel'] = self::CRITICO;
        } elseif (($var = $this->variacao($r, $roas($p))) !== null && $var <= -self::QUEDA_ROAS) {
            $alertas[] = $this->alerta(self::ATENCAO, 'ads', $canal, 'ROAS', "{$nome} ADS: ROAS caiu {$this->pct(-$var)} ({$this->um($r)} contra {$this->um($roas($p))}).", 'Separe a causa: CTR caiu (capa/preço), CPC subiu (concorrência) ou conversão caiu (página/preço).', $a['gasto'] / 2, $link);
            $m['roas']['nivel'] = self::ATENCAO;
        } elseif ($r === null && $a['pedidos'] === 0) {
            $alertas[] = $this->alerta(self::CRITICO, 'ads', $canal, 'ROAS', "{$nome} ADS: R$ {$this->moeda($a['gasto'])} investidos sem nenhum pedido atribuído.", 'Pause ou troque capa, preço e palavras antes de continuar.', $a['gasto'], $link);
            $m['roas']['nivel'] = self::CRITICO;
        }

        if ($tacos !== null && $tacos >= self::TACOS[0]) {
            $nivel = $tacos >= self::TACOS[1] ? self::CRITICO : self::ATENCAO;
            $alertas[] = $this->alerta($nivel, 'ads', $canal, 'TACOS', "{$nome}: o ADS consome {$this->pct($tacos)} de todo o faturamento do canal.", 'Acima de '.self::TACOS[0].'% o canal depende demais de ADS: invista em conversão orgânica (fotos, avaliações, preço).', $a['gasto'] / 3, $link);
            $m['tacos']['nivel'] = $nivel;
        }

        $c = $ctr($a);
        if ($c !== null && $a['impressoes'] >= 1000 && isset(self::CTR_MINIMO[$canal]) && $c < self::CTR_MINIMO[$canal]) {
            $alertas[] = $this->alerta(self::ATENCAO, 'ads', $canal, 'CTR', "{$nome} ADS: CTR de {$this->pct($c)}, abaixo de {$this->pct(self::CTR_MINIMO[$canal])}. Pouca gente clica no que vê.", 'Troque a foto de capa e confira se o preço aparece competitivo na busca.', 0, $link);
            $m['ctr']['nivel'] = self::ATENCAO;
        } elseif (($var = $this->variacao($c, $ctr($p))) !== null && $a['impressoes'] >= 1000 && $var <= -self::QUEDA_CTR) {
            $alertas[] = $this->alerta(self::ATENCAO, 'ads', $canal, 'CTR', "{$nome} ADS: CTR caiu {$this->pct(-$var)} ({$this->pct($c)} contra {$this->pct($ctr($p))}).", 'Concorrente novo ou capa cansada: teste outra capa.', 0, $link);
            $m['ctr']['nivel'] = self::ATENCAO;
        }

        if (($var = $this->variacao($cpc($a), $cpc($p))) !== null && $a['cliques'] >= 100 && $var >= self::ALTA_CPC) {
            $alertas[] = $this->alerta(self::ATENCAO, 'ads', $canal, 'CPC', "{$nome} ADS: o clique ficou {$this->pct($var)} mais caro (R$ {$this->moeda($cpc($a))} contra R$ {$this->moeda($cpc($p))}).", 'Mais concorrência no leilão: reveja lance/meta e palavras que mais gastam.', 0, $link);
            $m['cpc']['nivel'] = self::ATENCAO;
        }

        $custo = $cpa($a);
        if ($custo !== null && $lucroPorPedido !== null && $lucroPorPedido > 0 && $custo > $lucroPorPedido && $m['roas']['nivel'] === null) {
            $alertas[] = $this->alerta(self::CRITICO, 'ads', $canal, 'CPA', "{$nome} ADS: cada pedido do ADS custa R$ {$this->moeda($custo)}, mais que o lucro médio de um pedido (R$ {$this->moeda($lucroPorPedido)}).", 'Tire do ADS os itens de ticket baixo e concentre nos que têm margem.', $a['gasto'] / 2, $link);
            $m['cpa']['nivel'] = self::CRITICO;
        } elseif (($var = $this->variacao($custo, $cpa($p))) !== null && $a['pedidos'] >= 5 && $var >= self::ALTA_CPA) {
            $alertas[] = $this->alerta(self::ATENCAO, 'ads', $canal, 'CPA', "{$nome} ADS: o custo por pedido subiu {$this->pct($var)} (R$ {$this->moeda($custo)} contra R$ {$this->moeda($cpa($p))}).", 'Veja se a conversão caiu ou o clique encareceu.', 0, $link);
            $m['cpa']['nivel'] = self::ATENCAO;
        }

        $cv = $conv($a);
        if ($cv !== null && $a['cliques'] >= 100 && isset(self::CONVERSAO_ADS_MINIMA[$canal]) && $cv < self::CONVERSAO_ADS_MINIMA[$canal]) {
            $alertas[] = $this->alerta(self::ATENCAO, 'ads', $canal, 'Conversão do ADS', "{$nome} ADS: só {$this->pct($cv)} dos cliques viram pedido (mín. {$this->pct(self::CONVERSAO_ADS_MINIMA[$canal])}).", 'Muito clique e pouca venda: o problema está depois do clique (preço, fotos, descrição, frete, avaliações).', 0, $link);
            $m['conversao_ads']['nivel'] = self::ATENCAO;
        }

        return array_values($m);
    }

    private function metricasDoSite(Carbon $desde, Carbon $ate, Carbon $antes, ?array $a, ?array $p, array &$alertas): array
    {
        $vAtual = $this->visitantes(SiteVisit::SITE_LOJA, $desde, $ate);
        $vAnterior = $this->visitantes(SiteVisit::SITE_LOJA, $antes, $desde);
        $conv = fn (int $v, ?array $vendas) => $v > 0 ? ($vendas['pedidos'] ?? 0) / $v * 100 : null;

        $m = array_column([
            $this->metrica('visitantes', 'Visitantes únicos', $vAtual, $vAnterior, 'numero', 'alto'),
            $this->metrica('conversao_site', 'Conversão do site', $conv($vAtual, $a), $conv($vAnterior, $p), 'pct', 'alto', 'mín. '.$this->pct(self::CONVERSAO_SITE_MINIMA)),
        ], null, 'chave');

        $var = $this->variacao($vAtual, $vAnterior);
        if ($vAnterior >= self::VISITANTES_MINIMOS && $var !== null && $var <= -self::QUEDA_VISITANTES) {
            $alertas[] = $this->alerta(self::ATENCAO, 'site', Order::ORIGIN_STORE, 'Visitantes', "Site: visitantes únicos caíram {$this->pct(-$var)} ({$vAtual} contra {$vAnterior}).", 'Confira se alguma campanha (Meta, Google, Instagram) parou ou se o site ficou fora do ar.', 0, '/admin/visitas-site');
            $m['visitantes']['nivel'] = self::ATENCAO;
        }
        $c = $conv($vAtual, $a);
        if ($vAtual >= self::VISITANTES_MINIMOS * 4 && $c !== null && $c < self::CONVERSAO_SITE_MINIMA) {
            $alertas[] = $this->alerta(self::ATENCAO, 'site', Order::ORIGIN_STORE, 'Conversão do site', "Site: {$vAtual} visitantes e ".($a['pedidos'] ?? 0)." pedidos, conversão de {$this->pct($c)} (mín. {$this->pct(self::CONVERSAO_SITE_MINIMA)}).", 'Teste o caminho de compra no celular (produto, carrinho, frete, pagamento) e o preço contra os marketplaces.');
            $m['conversao_site']['nivel'] = self::ATENCAO;
        }

        return array_values($m);
    }

    /** FerraKora, BeautyKora e os outros sites que avisam visita na mesma tabela. */
    private function outrosSites(Carbon $desde, Carbon $ate, Carbon $antes, array &$alertas, array &$canais): void
    {
        foreach (SiteVisit::OUTROS_SITES as $site => $nome) {
            $atual = $this->visitantes($site, $desde, $ate);
            $anterior = $this->visitantes($site, $antes, $desde);
            if ($atual === 0 && $anterior === 0) {
                continue;
            }
            $m = $this->metrica('visitantes', 'Visitantes únicos', $atual, $anterior, 'numero', 'alto');
            $var = $this->variacao($atual, $anterior);
            if ($anterior >= self::VISITANTES_MINIMOS && $var !== null && $var <= -self::QUEDA_VISITANTES) {
                $alertas[] = $this->alerta(self::ATENCAO, 'site', $site, 'Visitantes', "{$nome}: visitantes únicos caíram {$this->pct(-$var)} ({$atual} contra {$anterior}).", 'Confira se o site está no ar e se a divulgação parou.', 0, "/admin/visitas-{$site}");
                $m['nivel'] = self::ATENCAO;
            }
            $canais[] = ['canal' => $site, 'nome' => $nome, 'metricas' => [$m]];
        }
    }

    /** Campanhas de ADS da Shopee que gastam sem vender ou dão prejuízo (mesma conta da Estratégia Shopee). */
    private function shopeeCampanhas(Carbon $desde, Carbon $ate, array &$alertas): void
    {
        if (! Schema::hasTable('shopee_ad_campanhas')) {
            return;
        }
        try {
            $k = $this->estrategia->gerar($desde, $ate->copy()->subDay())['kpis'];
        } catch (Throwable) {
            return;
        }
        if (($k['gasto_sem_venda'] ?? 0) >= 20) {
            $alertas[] = $this->alerta(self::CRITICO, 'ads', Order::ORIGIN_SHOPEE, 'Gasto sem venda', "Shopee ADS: R$ {$this->moeda($k['gasto_sem_venda'])} gastos em campanhas sem nenhuma venda.", 'Pause essas campanhas na Estratégia Shopee (botão Ação).', $k['gasto_sem_venda'] * 2, '/admin/publicidade/shopee');
        }
        if (($k['em_prejuizo'] ?? 0) > 0 && ($k['prejuizo'] ?? 0) <= -20) {
            $alertas[] = $this->alerta(self::CRITICO, 'ads', Order::ORIGIN_SHOPEE, 'Campanhas com prejuízo', "Shopee ADS: {$k['em_prejuizo']} campanhas deram prejuízo de R$ {$this->moeda(-$k['prejuizo'])} no período.", 'Decida na Estratégia Shopee: pausar ou subir a meta para empate + 2.', -$k['prejuizo'] * 2, '/admin/publicidade/shopee');
        }
    }

    /** O que trava a venda de amanhã: envio atrasado, estoque acabando e venda sem custo. */
    private function operacao(array &$alertas): void
    {
        $atrasados = Order::query()->vendaEfetiva()
            ->whereIn('origin', self::CANAIS_COM_ENVIO)
            ->where('status', Order::STATUS_PAID)
            ->where('created_at', '<', now()->subHours(self::HORAS_ENVIO))
            ->where('created_at', '>=', now()->subDays(20))
            ->selectRaw('origin, COUNT(*) as total')->groupBy('origin')->pluck('total', 'origin');
        foreach ($atrasados as $canal => $total) {
            $nivel = $total >= 5 ? self::CRITICO : self::ATENCAO;
            $alertas[] = $this->alerta($nivel, 'operacao', $canal, 'Envio atrasado', self::CANAIS[$canal].": {$total} pedido(s) pago(s) há mais de ".self::HORAS_ENVIO.'h sem sair para envio.', 'Atraso de envio derruba a reputação e o alcance dos anúncios: confira a fila de etiquetas.', $total * 20, '/admin/pedidos');
        }

        // Estoque × velocidade de venda dos últimos 30 dias (todos os canais).
        $vendidos = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->whereIn('o.status', ContributionMargin::REVENUE_STATUSES)
            ->where('o.created_at', '>=', Carbon::today()->subDays(30))
            ->where('p.is_active', true)
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q, 'o'))
            ->selectRaw('p.id, p.name, p.stock, SUM(oi.quantity) as unidades')
            ->groupBy('p.id', 'p.name', 'p.stock')
            ->havingRaw('SUM(oi.quantity) >= 3')
            ->get();
        $zerados = $vendidos->filter(fn ($p) => (int) $p->stock <= 0)->sortByDesc('unidades')->values();
        $acabando = $vendidos->filter(fn ($p) => (int) $p->stock > 0 && $p->stock / ($p->unidades / 30) < self::COBERTURA_DIAS)->sortByDesc('unidades')->values();
        if ($zerados->isNotEmpty()) {
            $nomes = $zerados->take(3)->pluck('name')->implode(', ');
            $alertas[] = $this->alerta(self::CRITICO, 'estoque', null, 'Ruptura', "{$zerados->count()} produto(s) que vendem bem estão sem estoque: {$nomes}.", 'Reponha ou pause o ADS deles: anúncio sem estoque perde posição.', $zerados->sum('unidades') * 10, '/admin/produtos');
        }
        if ($acabando->isNotEmpty()) {
            $nomes = $acabando->take(3)->map(fn ($p) => "{$p->name} ({$p->stock} un.)")->implode(', ');
            $alertas[] = $this->alerta(self::ATENCAO, 'estoque', null, 'Cobertura de estoque', "{$acabando->count()} produto(s) com estoque para menos de ".self::COBERTURA_DIAS." dias de venda: {$nomes}.", 'Faça o pedido de compra agora para não zerar.', $acabando->sum('unidades'), '/admin/produtos');
        }

        $semCusto = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->whereIn('o.status', ContributionMargin::REVENUE_STATUSES)
            ->where('o.created_at', '>=', Carbon::today()->subDays(30))
            ->whereNull('oi.manual_cost_price')
            ->where(fn ($q) => $q->whereNull('p.cost_price')->orWhere('p.cost_price', '<=', 0))
            ->distinct()->count('oi.product_id');
        if ($semCusto > 0) {
            $alertas[] = $this->alerta(self::ATENCAO, 'lucro', null, 'Produto sem custo', "{$semCusto} produto(s) vendidos nos últimos 30 dias sem custo cadastrado: a margem e o ROAS de empate deles estão errados (parecem mais lucrativos do que são).", 'Cadastre o custo em Produtos.', $semCusto * 5, '/admin/produtos');
        }

        // ADS que parou de sincronizar: os números acima ficam velhos sem aviso.
        $ultimo = ChannelAdSpend::query()->selectRaw('channel, MAX(date) as ultimo')->groupBy('channel')->pluck('ultimo', 'channel');
        foreach ($ultimo as $canal => $data) {
            if ($data && Carbon::parse($data)->lt(Carbon::today()->subDays(2))) {
                $alertas[] = $this->alerta(self::ATENCAO, 'ads', $canal, 'Sincronização do ADS', (self::CANAIS[$canal] ?? $canal).': gasto de ADS sem atualizar desde '.Carbon::parse($data)->format('d/m').'.', 'Confira a conexão do canal em Integrações (ads:sync-spend).', 0, '/admin/integracoes');
            }
        }
    }

    private function visitantes(string $site, Carbon $desde, Carbon $ate): int
    {
        $equipe = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $porVisitante = SiteVisit::visitantesPorDia(
            SiteVisit::query()->where('site_visits.site', $site)->where('created_at', '>=', $desde)->where('created_at', '<', $ate),
            $equipe,
        );

        return SiteVisit::soDeFora($porVisitante)->pluck('visitante')->unique()->count();
    }

    private function metrica(string $chave, string $nome, int|float|null $valor, int|float|null $anterior, string $formato, string $bom, ?string $referencia = null): array
    {
        return [
            'chave' => $chave,
            'nome' => $nome,
            'valor' => $valor === null ? null : round((float) $valor, 2),
            'anterior' => $anterior === null ? null : round((float) $anterior, 2),
            'variacao' => $this->variacao($valor, $anterior),
            'formato' => $formato,
            'bom' => $bom,
            'nivel' => null,
            'referencia' => $referencia,
        ];
    }

    /** Variação % do atual sobre o anterior; null sem base de comparação. */
    private function variacao(int|float|null $atual, int|float|null $anterior): ?float
    {
        if ($atual === null || $anterior === null || (float) $anterior == 0.0) {
            return null;
        }

        return round(($atual - $anterior) / abs($anterior) * 100, 1);
    }

    private function alerta(string $nivel, string $grupo, ?string $canal, string $indicador, string $texto, string $acao, float $peso = 0, ?string $link = null): array
    {
        return compact('nivel', 'grupo', 'canal', 'indicador', 'texto', 'acao', 'link') + ['peso' => $peso];
    }

    private function moeda(int|float|null $v): string
    {
        return number_format((float) $v, 2, ',', '.');
    }

    private function pct(int|float|null $v): string
    {
        return number_format((float) $v, 1, ',', '.').'%';
    }

    private function um(int|float|null $v): string
    {
        return number_format((float) $v, 1, ',', '.');
    }
}
