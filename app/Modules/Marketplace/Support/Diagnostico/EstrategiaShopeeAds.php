<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Models\ShopeeAdCampanha;
use App\Modules\Marketplace\Models\ShopeeAdCampanhaDia;
use App\Modules\Marketplace\Models\ShopeeAdsAcao;
use Illuminate\Support\Carbon;

/**
 * Estratégia Shopee (menu Publicidade, pedido do Lira em 2026-10-14): cada
 * campanha de ADS com o resultado em reais e uma situação para decidir de
 * olho. Fase 1, só leitura (escopo em Documents/2026-10-14-shopee-tela-
 * estrategia-ads.md do Homer).
 *
 *   resultado do ADS = venda atribuída × margem antes do ADS − gasto
 *                      (= gasto × (ROAS ÷ empate − 1))
 *
 * Empate e meta vêm do EconomiaDosAnuncios (mesma conta da
 * ContributionMargin). Mesmos limites do diagnóstico do Cauê: R$ 20 sem
 * venda, R$ 10 para julgar ROAS, CTR 2%, conversão 1%.
 */
class EstrategiaShopeeAds
{
    public const GASTO_SEM_VENDA = 20.0;

    public const GASTO_MINIMO = 10.0;

    public const CLIQUES_MINIMOS = 30;

    /** Troca de meta some da lista pelo aprendizado da Shopee (cerca de 14 dias). */
    public const DIAS_DECISAO_META = 14;

    /** Situações na ordem em que aparecem nos filtros. */
    public const SITUACOES = [
        'queimando' => 'Queimando sem venda',
        'prejuizo' => 'Prejuízo',
        'sem_margem' => 'Margem não aguenta ADS',
        'margem_fina' => 'Margem fina',
        'saudavel' => 'Saudável',
        'escalar' => 'Escalar',
        'pouco_dado' => 'Pouco dado',
        'sem_custo' => 'Sem custo',
    ];

    public function __construct(private readonly EconomiaDosAnuncios $economia) {}

    public function gerar(Carbon $desde, Carbon $ate): array
    {
        $desde = $desde->copy()->startOfDay();
        $ate = $ate->copy()->startOfDay();
        $dias = $desde->diffInDays($ate) + 1;

        $periodo = $this->somas($desde, $ate);
        $ultimos7 = $this->somas($ate->copy()->subDays(6), $ate);
        $anteriores7 = $this->somas($ate->copy()->subDays(13), $ate->copy()->subDays(7));

        $campanhas = ShopeeAdCampanha::query()->get()
            ->filter(fn ($c) => in_array($c->status, ShopeeAdCampanha::ATIVAS, true) || ($periodo[$c->campaign_id]['gasto'] ?? 0) > 0);

        $vendas = $this->economia->porAnuncio('shopee', $desde);
        $taxaMedia = $this->economia->taxaMedia('shopee', $desde);
        $produtos = $this->produtosPorItem($campanhas->pluck('item_id')->filter()->unique()->values()->all());

        $linhas = [];
        foreach ($campanhas as $c) {
            $s = $periodo[$c->campaign_id] ?? self::vazio();
            $produto = $c->item_id ? ($produtos[$c->item_id] ?? null) : null;
            $eco = null;
            if ($produto) {
                $eco = $vendas[$c->item_id] ?? $this->economia->estimar($produto['preco'], $produto['custo'], $taxaMedia);
            }
            $linhas[] = $this->linha($c, $s, $eco, $produto, $dias, $ultimos7[$c->campaign_id] ?? null, $anteriores7[$c->campaign_id] ?? null, $vendas[$c->item_id] ?? null);
        }

        // Maior prejuízo em reais primeiro: a primeira linha é a decisão mais cara.
        usort($linhas, fn ($a, $b) => [$a['resultado'] ?? 0, -$a['gasto']] <=> [$b['resultado'] ?? 0, -$b['gasto']]);

        // Campanha já pausada ou com meta trocada pelo botão Ação sai da lista
        // e do "Decida hoje" (pedido do Lira, 2026-10-14); os cards seguem
        // contando o dinheiro dela, que já foi gasto.
        $decisoes = $this->decisoes($campanhas);
        $abertas = [];
        $decididas = [];
        foreach ($linhas as $l) {
            if (isset($decisoes[$l['campaign_id']])) {
                $decididas[] = $l + ['decisao' => $decisoes[$l['campaign_id']]];
            } else {
                $abertas[] = $l;
            }
        }

        return [
            'desde' => $desde->toDateString(),
            'ate' => $ate->toDateString(),
            'dias' => $dias,
            'kpis' => $this->kpis($linhas),
            'decidir' => array_values(array_slice(array_filter($abertas, fn ($l) => ($l['resultado'] ?? 0) < 0 && in_array($l['situacao'], ['queimando', 'prejuizo', 'sem_margem'], true)), 0, 5)),
            'campanhas' => $abertas,
            'decididas' => $decididas,
            'conferencia' => $this->conferencia($desde, $ate, array_sum(array_column($linhas, 'gasto'))),
            'sincronizado_em' => ShopeeAdCampanha::query()->max('sincronizado_em'),
            'situacoes' => self::SITUACOES,
        ];
    }

    /**
     * Decisão em vigor por campanha, pela última ação do botão Ação que não
     * deu erro nem foi desfeita: pausa vale até a campanha voltar a rodar
     * (retomada aqui ou, depois da pausa, sincronizada ativa); troca de meta
     * vale pelo aprendizado da Shopee (DIAS_DECISAO_META).
     *
     * @return array<string, array{acao: string, descricao: string, quando: ?string, quem: ?string, simulada: bool}>
     */
    private function decisoes($campanhas): array
    {
        $porId = $campanhas->keyBy(fn ($c) => (string) $c->campaign_id);
        $ultimas = ShopeeAdsAcao::query()->with('user:id,name')
            ->whereIn('campaign_id', $porId->keys()->all())
            ->where('status', '!=', ShopeeAdsAcao::STATUS_ERRO)
            ->whereNull('desfeita_em')
            ->orderBy('id')->get()
            ->keyBy(fn ($a) => (string) $a->campaign_id);

        $decisoes = [];
        foreach ($ultimas as $id => $a) {
            $c = $porId[$id];
            $vale = $a->desfaz_id === null && match ($a->acao) {
                ShopeeAdsAcao::ACAO_PAUSAR => ! (in_array($c->status, ShopeeAdCampanha::ATIVAS, true) && $c->sincronizado_em?->gt($a->created_at)),
                ShopeeAdsAcao::ACAO_META => $a->created_at->gt(now()->subDays(self::DIAS_DECISAO_META)),
                default => false,
            };
            if ($vale) {
                $decisoes[$id] = [
                    'acao' => $a->acao,
                    'descricao' => $a->descricao(),
                    'quando' => $a->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                    'quem' => $a->user?->name,
                    'simulada' => $a->status === ShopeeAdsAcao::STATUS_SIMULADA,
                ];
            }
        }

        return $decisoes;
    }

    private function linha(ShopeeAdCampanha $c, array $s, ?array $eco, ?array $produto, int $dias, ?array $u7, ?array $a7, ?array $vendido): array
    {
        $roas = $s['gasto'] > 0 ? $s['venda'] / $s['gasto'] : null;
        $ctr = $s['impressoes'] > 0 ? $s['cliques'] / $s['impressoes'] * 100 : null;
        $conversao = $s['cliques'] > 0 ? $s['pedidos'] / $s['cliques'] * 100 : null;
        $semCusto = ! $eco || ($eco['sem_custo'] ?? false);
        $empate = $semCusto ? null : $eco['roas_empate'];
        $meta = $semCusto ? null : $eco['roas_meta'];
        $metaPraticavel = $meta && $meta <= 25 ? $meta : null;
        $margem = ! $semCusto && $eco['preco'] > 0 ? $eco['lucro_unidade'] / $eco['preco'] : null;
        $resultado = $margem !== null ? round($s['venda'] * $margem - $s['gasto'], 2) : null;

        $estoque = $produto['estoque'] ?? null;
        $vendaDia = $vendido ? $vendido['unidades'] / max(1, $dias) : 0;
        $diasEstoque = $estoque !== null && $vendaDia > 0 ? (int) floor($estoque / $vendaDia) : null;

        $situacao = $this->situacao($s, $semCusto, $eco, $roas, $empate, $metaPraticavel, $conversao, $estoque, $diasEstoque);
        $causa = $this->causa($situacao, $s, $ctr, $conversao, $metaPraticavel);

        return [
            'campaign_id' => $c->campaign_id,
            'nome' => $produto['nome'] ?? $c->nome ?? "Campanha {$c->campaign_id}",
            'item_id' => $c->item_id,
            'sku' => $produto['sku'] ?? null,
            // Página do produto. A da campanha na Shopee Ads (portal/marketing/
            // pas/product/manual/{campaign_id}) só foi vista na extensão do
            // Seller Bump e não foi conferida para campanha automática.
            'link' => $c->item_id ? "https://seller.shopee.com.br/portal/product/{$c->item_id}" : null,
            'status' => $c->status,
            'ativa' => in_array($c->status, ShopeeAdCampanha::ATIVAS, true),
            'tipo' => $c->ad_type === 'auto' ? 'automatica' : ($c->lance === 'auto' ? 'meta_roas' : 'manual'),
            'orcamento' => $c->orcamento !== null ? (float) $c->orcamento : null,
            'meta_configurada' => $c->meta_roas !== null ? (float) $c->meta_roas : null,
            'gasto' => round($s['gasto'], 2),
            'impressoes' => $s['impressoes'],
            'cliques' => $s['cliques'],
            'pedidos' => $s['pedidos'],
            'venda' => round($s['venda'], 2),
            'pedidos_diretos' => $s['pedidos_diretos'],
            'venda_direta' => round($s['venda_direta'], 2),
            'roas' => $roas !== null ? round($roas, 1) : null,
            'roas_empate' => $empate,
            'roas_meta' => $meta,
            'margem' => $margem !== null ? round($margem * 100, 1) : null,
            'preco_minimo' => $eco['preco_minimo'] ?? null,
            'estimado' => (bool) ($eco['estimado'] ?? false),
            'resultado' => $resultado,
            'ctr' => $ctr !== null ? round($ctr, 1) : null,
            'conversao' => $conversao !== null ? round($conversao, 1) : null,
            'cpc' => $s['cliques'] > 0 ? round($s['gasto'] / $s['cliques'], 2) : null,
            'acos' => $s['venda'] > 0 ? round($s['gasto'] / $s['venda'] * 100, 1) : null,
            'tendencia' => $this->tendencia($u7, $a7),
            'estoque' => $estoque,
            'dias_estoque' => $diasEstoque,
            'situacao' => $situacao,
            'causa' => $causa,
            'sugestao' => $this->sugestao($situacao, $causa, $metaPraticavel, $c->meta_roas !== null ? (float) $c->meta_roas : null, $eco['preco_minimo'] ?? null, $produto === null),
        ];
    }

    private function situacao(array $s, bool $semCusto, ?array $eco, ?float $roas, ?float $empate, ?float $meta, ?float $conversao, ?int $estoque, ?int $diasEstoque): string
    {
        if ($s['gasto'] >= self::GASTO_SEM_VENDA && $s['pedidos'] === 0) {
            return 'queimando';
        }
        if ($s['gasto'] < self::GASTO_MINIMO || $s['cliques'] < self::CLIQUES_MINIMOS) {
            return 'pouco_dado';
        }
        if ($semCusto) {
            return 'sem_custo';
        }
        if ($eco['lucro_unidade'] <= 0 || $empate === null) {
            return 'sem_margem';
        }
        if ($roas < $empate) {
            return 'prejuizo';
        }
        if ($meta === null || $roas < $meta) {
            return 'margem_fina';
        }
        if ($roas >= $meta * 1.3 && ($conversao ?? 0) >= 2 && ($estoque ?? 0) > 0 && ($diasEstoque === null || $diasEstoque >= 30)) {
            return 'escalar';
        }

        return 'saudavel';
    }

    /** CTR × conversão: o que provavelmente está errado. */
    private function causa(string $situacao, array $s, ?float $ctr, ?float $conversao, ?float $meta): ?string
    {
        if (in_array($situacao, ['pouco_dado', 'saudavel', 'escalar'], true)) {
            return null;
        }
        if ($situacao === 'sem_margem' || $situacao === 'sem_custo') {
            return 'margem';
        }
        if ($s['impressoes'] >= 500 && $ctr !== null && $ctr < 2) {
            return 'capa';
        }
        if ($situacao === 'queimando' || ($s['cliques'] >= 100 && $conversao !== null && $conversao < 1)) {
            return 'pagina';
        }

        return $meta === null ? 'margem' : 'lance';
    }

    private function sugestao(string $situacao, ?string $causa, ?float $meta, ?float $metaConfigurada, ?float $precoMinimo, bool $semVinculo): string
    {
        $um = fn ($v) => number_format((float) $v, 1, ',', '.');
        $preco = $precoMinimo ? ' Preço mínimo para aguentar ADS: R$ '.number_format($precoMinimo, 2, ',', '.').'.' : '';
        $porque = match ($causa) {
            'capa' => ' Quase ninguém clica: troque a capa.',
            'pagina' => ' Clicam e não compram: revise preço, fotos e frete.',
            default => '',
        };

        return match ($situacao) {
            'queimando' => 'Pause: gastou sem nenhuma venda.'.$porque,
            'prejuizo' => $meta
                ? "Suba a meta de ROAS para {$um($meta)}".($metaConfigurada ? " (hoje {$um($metaConfigurada)})" : '').' ou pause.'.$porque
                : 'Pause: a margem é fina demais para ADS.'.$preco,
            'sem_margem' => 'Pause e revise o preço: cada venda já dá prejuízo antes do ADS.'.$preco,
            'margem_fina' => $meta
                ? "Não perde, mas não deixa 15%. Suba a meta para {$um($meta)}."
                : 'Não perde, mas a margem não deixa 15% com ADS.'.$preco,
            'escalar' => 'Vende bem acima da meta: aumente o orçamento.',
            'saudavel' => 'Está dando lucro. Manter.',
            'sem_custo' => $semVinculo ? 'Anúncio não ligado a um produto do KazaKora: ligue para calcular o lucro.' : 'Cadastre o custo do produto para saber se o ADS dá lucro.',
            default => 'Pouco gasto ou poucos cliques ainda: espere antes de decidir.',
        };
    }

    private function tendencia(?array $u7, ?array $a7): ?string
    {
        if (! $u7 || ! $a7 || $u7['gasto'] < 5 || $a7['gasto'] < 5) {
            return null;
        }
        $agora = $u7['venda'] / $u7['gasto'];
        $antes = $a7['venda'] / $a7['gasto'];
        if ($antes == 0.0) {
            return $agora > 0 ? 'subindo' : 'estavel';
        }

        return $agora > $antes * 1.15 ? 'subindo' : ($agora < $antes * 0.85 ? 'caindo' : 'estavel');
    }

    private function kpis(array $linhas): array
    {
        $gasto = array_sum(array_column($linhas, 'gasto'));
        $venda = array_sum(array_column($linhas, 'venda'));
        $comGasto = array_filter($linhas, fn ($l) => $l['gasto'] > 0);
        $perdendo = array_filter($linhas, fn ($l) => ($l['resultado'] ?? 0) < 0);
        $metas = [];
        foreach ($linhas as $l) {
            if ($l['roas_meta'] && $l['roas_meta'] <= 25 && $l['venda'] > 0) {
                $metas[] = [$l['roas_meta'], $l['venda']];
            }
        }

        return [
            'gasto' => round($gasto, 2),
            'venda' => round($venda, 2),
            'roas' => $gasto > 0 ? round($venda / $gasto, 1) : null,
            'roas_meta_loja' => DiagnosticoShopee::mediaPonderada($metas),
            'resultado' => round(array_sum(array_filter(array_column($linhas, 'resultado'), fn ($v) => $v !== null)), 2),
            'prejuizo' => round(array_sum(array_column($perdendo, 'resultado')), 2),
            'em_prejuizo' => count($perdendo),
            'com_gasto' => count($comGasto),
            'ativas' => count(array_filter($linhas, fn ($l) => $l['ativa'])),
            'gasto_sem_venda' => round(array_sum(array_column(array_filter($linhas, fn ($l) => $l['situacao'] === 'queimando'), 'gasto')), 2),
        ];
    }

    /**
     * Soma das campanhas × gasto da loja inteira (channel_ad_spends).
     * diferenca > 0: a Shopee Ads gastou mais do que aparece nas campanhas
     * (campanha da loja inteira ou sincronização atrasada).
     */
    private function conferencia(Carbon $desde, Carbon $ate, float $gastoCampanhas): array
    {
        $loja = (float) ChannelAdSpend::query()->where('channel', 'shopee')
            ->whereBetween('date', [$desde->toDateString(), $ate->toDateString()])->sum('spend');
        $diferenca = $loja > 0 ? abs($gastoCampanhas - $loja) / $loja * 100 : null;

        return [
            'gasto_loja' => round($loja, 2),
            'gasto_campanhas' => round($gastoCampanhas, 2),
            'diferenca' => $loja > 0 ? round($loja - $gastoCampanhas, 2) : null,
            'diferenca_pct' => $diferenca !== null ? round($diferenca, 1) : null,
            'confere' => $diferenca === null || $diferenca <= 2,
        ];
    }

    /** @return array<int, array{gasto: float, venda: float, impressoes: int, cliques: int, pedidos: int, pedidos_diretos: int, venda_direta: float}> */
    private function somas(Carbon $desde, Carbon $ate): array
    {
        return ShopeeAdCampanhaDia::query()
            ->whereBetween('data', [$desde->toDateString(), $ate->toDateString()])
            ->groupBy('campaign_id')
            ->selectRaw('campaign_id, SUM(gasto) as gasto, SUM(venda) as venda, SUM(impressoes) as impressoes, SUM(cliques) as cliques,
                SUM(pedidos) as pedidos, SUM(pedidos_diretos) as pedidos_diretos, SUM(venda_direta) as venda_direta')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->campaign_id => [
                'gasto' => (float) $r->gasto,
                'venda' => (float) $r->venda,
                'impressoes' => (int) $r->impressoes,
                'cliques' => (int) $r->cliques,
                'pedidos' => (int) $r->pedidos,
                'pedidos_diretos' => (int) $r->pedidos_diretos,
                'venda_direta' => (float) $r->venda_direta,
            ]])
            ->all();
    }

    private static function vazio(): array
    {
        return ['gasto' => 0.0, 'venda' => 0.0, 'impressoes' => 0, 'cliques' => 0, 'pedidos' => 0, 'pedidos_diretos' => 0, 'venda_direta' => 0.0];
    }

    /**
     * Produto do KazaKora de cada item da Shopee: nome, SKU, preço do
     * anúncio, custo médio e estoque somado (anúncio com variações tem um
     * produto por variação).
     *
     * @return array<string, array{nome: string, sku: ?string, preco: float, custo: ?float, estoque: int}>
     */
    private function produtosPorItem(array $itens): array
    {
        if ($itens === []) {
            return [];
        }
        $anuncios = ProductChannelListing::query()->where('channel', 'shopee')->whereIn('external_id', $itens)->get(['product_id', 'external_id', 'price']);
        $produtos = Product::query()->whereIn('id', $anuncios->pluck('product_id'))->get(['id', 'name', 'sku', 'price', 'cost_price', 'stock'])->keyBy('id');

        return $anuncios->groupBy(fn ($l) => (string) $l->external_id)->map(function ($grupo) use ($produtos) {
            $ps = $grupo->map(fn ($l) => $produtos->get($l->product_id))->filter();
            if ($ps->isEmpty()) {
                return null;
            }
            $preco = (float) ($grupo->pluck('price')->filter()->avg() ?: $ps->pluck('price')->filter()->avg());
            $custo = $ps->pluck('cost_price')->filter()->avg();

            return [
                'nome' => (string) $ps->first()->name,
                'sku' => $ps->first()->sku,
                'preco' => $preco,
                'custo' => $custo !== null ? (float) $custo : null,
                'estoque' => (int) $ps->sum('stock'),
            ];
        })->filter()->all();
    }
}
