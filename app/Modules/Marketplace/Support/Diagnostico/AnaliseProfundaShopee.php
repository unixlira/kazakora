<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Models\ShopeeAdCampanha;
use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\ShopeeMetricas\LeitorShopeeMetricas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estatísticas › Análise Profunda de Conta · Shopee (pedido do Lira em
 * 2026-10-26; especificação do Jonathan em Documents/2026-10-26-analise-
 * profunda-conta-shopee.md do Homer). Junta numa tela só:
 *
 *   - KazaKora: vendas e lucro pela ContributionMargin (conta oficial, sem
 *     conta nova), pedidos por hora, estoque, Relâmpagos, avaliações e
 *     devoluções;
 *   - API: ADS por campanha (EstrategiaShopeeAds) e o diagnóstico guardado
 *     do Cauê (status, fotos, vídeo e nota do anúncio);
 *   - Tela: funil do Seller Center (shopee_metricas).
 *
 * SÓ LEITURA: nada aqui chama a Shopee nem muda preço, ADS ou promoção.
 *
 * Alertas: só as regras "Em uso" e "Lira" da especificação. As marcadas
 * "Nova" (R12, R13, R15, R17, R18, R19, R21, R22, O2, O3, O5, O6, O7)
 * aguardam o Lira confirmar as réguas e não entram.
 */
class AnaliseProfundaShopee
{
    public const CANAL = 'shopee';

    // Réguas que já rodam no diagnóstico do Cauê e na Estratégia Shopee.
    private const CTR_MINIMO = 2.0;

    private const IMPRESSOES_MINIMAS = 500;

    private const CONVERSAO_MINIMA = 1.0;

    private const VISITANTES_MINIMOS = 100;

    private const ABANDONO_MAXIMO = 90.0;

    private const CARRINHO_MINIMO = 20;

    private const DIAS_ESTOQUE_ATENCAO = 15;

    /** Piso de lucro do Lira: 15% do que o cliente paga; Relâmpago, 15% do custo. */
    private const PISO = 15.0;

    private const GRAVIDADE = ['grave' => 0, 'atencao' => 1, 'melhorar' => 2];

    private ?array $diagnostico = null;

    public function __construct(
        private readonly ContributionMargin $margem,
        private readonly EconomiaDosAnuncios $economia,
        private readonly EstrategiaShopeeAds $estrategia,
        private readonly LeitorShopeeMetricas $leitor,
    ) {}

    /** @param Carbon $desde e $ate: dias inteiros, os dois incluídos */
    public function gerar(Carbon $desde, Carbon $ate): array
    {
        $desde = $desde->copy()->startOfDay();
        $ate = $ate->copy()->startOfDay();
        $dias = (int) $desde->diffInDays($ate) + 1;
        $antesAte = $desde->copy()->subDay();
        $antesDesde = $antesAte->copy()->subDays($dias - 1);
        $fim = $ate->copy()->addDay();

        $conta = $this->margem->conta($desde, $fim, self::CANAL);
        $contaAntes = $this->margem->conta($antesDesde, $desde, self::CANAL);

        $tela = $this->leitor->porItem('produto', $desde, $ate);
        $telaAntes = $this->leitor->porItem('produto', $antesDesde, $antesAte);
        $taxaMedia = $this->economia->taxaMedia(self::CANAL, $desde);

        $anuncios = $this->anuncios();
        $vendas = $this->vendasPorItem($desde, $fim, $anuncios['item_do_produto']);
        $ads = $this->adsPorItem($desde, $ate);

        $produtos = $this->produtos($tela['itens'], $anuncios['itens'], $vendas, $ads['itens'], $taxaMedia, $dias);
        $relampagos = $this->relampagos($anuncios, $taxaMedia);
        [$riscos, $oportunidades] = $this->alertas($produtos, $relampagos, $desde, $ate);

        $funil = $this->funilLoja($tela['itens']);
        $funilAntes = $this->funilLoja($telaAntes['itens']);
        $horas = $this->leitor->porHora($desde, $ate);
        $coletas = $this->leitor->ultimaColeta();

        return [
            'periodo' => [
                'desde' => $desde->toDateString(), 'ate' => $ate->toDateString(), 'dias' => $dias,
                'anterior' => ['desde' => $antesDesde->toDateString(), 'ate' => $antesAte->toDateString()],
            ],
            'status' => [
                'ads_sincronizado_em' => ShopeeAdCampanha::query()->max('sincronizado_em'),
                'tela_coletada_em' => $coletas['tela'] ?? null,
                'tela_atrasada' => isset($coletas['tela']) && Carbon::parse($coletas['tela'])->lt(now()->subHours(36)),
                'diagnostico_em' => $this->diagnostico()['gerado_em'] ?? null,
            ],
            'conta' => $conta,
            'conta_anterior' => $contaAntes,
            'serie' => array_map(fn ($b) => ['data' => $b['inicio'], 'receita' => $b['receita'], 'lucro' => $b['lucro'], 'pedidos' => $b['pedidos'] ?? 0],
                $this->margem->serie($desde, $fim, 'dia', self::CANAL)),
            'taxas' => ContributionMargin::qualidadeTaxas($desde, $fim)[self::CANAL] ?? null,
            'funil' => ['fonte' => $tela['fonte'], 'loja' => $funil, 'anterior' => $telaAntes['fonte'] ? $funilAntes : null,
                'fonte_anterior' => $telaAntes['fonte'], 'dias' => $horas['por_dia']],
            'ads' => $this->resumoAds($ads, $produtos, $dias),
            'horarios' => $this->horarios($desde, $fim, $horas),
            'produtos' => array_values($produtos),
            'riscos' => $riscos,
            'oportunidades' => $oportunidades,
            'relampagos' => $relampagos,
            'avaliacoes' => $this->avaliacoes($desde, $fim),
            'devolucoes' => $this->devolucoes($desde, $fim, (int) ($conta['pedidos'] ?? 0)),
            'saude' => $this->leitor->daLoja(),
            'fontes' => [
                'kazakora' => now()->toDateTimeString(),
                'ads' => ShopeeAdCampanha::query()->max('sincronizado_em'),
                'ads_coleta' => $coletas['api'] ?? null,
                'tela' => $coletas['tela'] ?? null,
                'diagnostico' => $this->diagnostico()['gerado_em'] ?? null,
            ],
        ];
    }

    /**
     * Anúncios da Shopee ligados a produtos do KazaKora: nome, SKU, preço,
     * custo médio e estoque somado (anúncio com variação tem um produto por
     * variação).
     */
    private function anuncios(): array
    {
        $listagens = ProductChannelListing::query()->where('channel', self::CANAL)->whereNotNull('external_id')
            ->get(['product_id', 'external_id', 'price', 'status']);
        $produtos = Product::query()->whereIn('id', $listagens->pluck('product_id')->unique())
            ->get(['id', 'name', 'sku', 'price', 'cost_price', 'stock'])->keyBy('id');

        $itens = [];
        $itemDoProduto = [];
        foreach ($listagens->groupBy(fn ($l) => (string) $l->external_id) as $item => $grupo) {
            $ps = $grupo->map(fn ($l) => $produtos->get($l->product_id))->filter();
            foreach ($grupo as $l) {
                $itemDoProduto[$l->product_id] ??= (string) $item;
            }
            if ($ps->isEmpty()) {
                continue;
            }
            $custo = $ps->pluck('cost_price')->filter(fn ($c) => $c !== null)->avg();
            $itens[(string) $item] = [
                'product_id' => (int) $ps->first()->id,
                'nome' => (string) $ps->first()->name,
                'sku' => $ps->first()->sku,
                'preco' => (float) ($grupo->pluck('price')->filter()->avg() ?: $ps->pluck('price')->filter()->avg()),
                'custo' => $custo !== null ? (float) $custo : null,
                'estoque' => (int) $ps->sum('stock'),
                'status' => (string) $grupo->first()->status,
            ];
        }

        return ['itens' => $itens, 'item_do_produto' => $itemDoProduto];
    }

    /**
     * Vendas de cada anúncio no período pela ContributionMargin::porItem
     * (receita, custo, taxa e frete repartidos pela conta oficial). Lucro
     * antes do ADS = margem da linha − imposto do Simples sobre a receita,
     * a mesma definição do EconomiaDosAnuncios.
     */
    private function vendasPorItem(Carbon $desde, Carbon $fim, array $itemDoProduto): array
    {
        $aliquota = (float) config('loja.simples.aliquota', 4.5) / 100;
        $porItem = [];
        foreach ($this->margem->porItem($desde, $fim, self::CANAL)['linhas'] as $l) {
            $item = $l['product_id'] !== null ? ($itemDoProduto[$l['product_id']] ?? null) : null;
            $chave = $item ?? 'p:'.($l['product_id'] ?? md5($l['product_name']));
            $v = $porItem[$chave] ?? ['item_id' => $item, 'nome' => $l['product_name'], 'product_id' => $l['product_id'], 'pedidos' => [],
                'unidades' => 0, 'receita' => 0.0, 'custo' => 0.0, 'taxas' => 0.0, 'frete' => 0.0, 'margem' => 0.0,
                'sem_custo' => false, 'momentos' => []];
            $v['pedidos'][$l['order_id']] = true;
            $v['unidades'] += $l['quantidade'];
            $v['receita'] += $l['receita'];
            $v['custo'] += $l['custo_produtos'];
            $v['taxas'] += $l['taxas'];
            $v['frete'] += $l['frete'];
            $v['margem'] += $l['margem'];
            $v['sem_custo'] = $v['sem_custo'] || ! $l['tem_custo'];
            $v['momentos'][$l['order_id']] = $l['pedido_em'];
            $porItem[$chave] = $v;
        }

        foreach ($porItem as &$v) {
            $unidades = max(1, $v['unidades']);
            $lucro = $v['margem'] - $v['receita'] * $aliquota;
            $v['pedidos'] = count($v['pedidos']);
            $v['lucro'] = round($lucro, 2);
            $v['eco'] = EconomiaDosAnuncios::indicadores($v['receita'] / $unidades, $lucro / $unidades, $v['custo'] / $unidades,
                $v['receita'] > 0 ? $v['taxas'] / $v['receita'] : 0.0) + ['sem_custo' => $v['sem_custo'], 'estimado' => false];
            $momentos = array_values($v['momentos']);
            sort($momentos);
            $v['ultima_venda'] = end($momentos) ?: null;
            $v['intervalo_horas'] = count($momentos) >= 2
                ? round(Carbon::parse($momentos[0])->diffInMinutes(Carbon::parse(end($momentos))) / (count($momentos) - 1) / 60, 1)
                : null;
            unset($v['momentos']);
        }

        return $porItem;
    }

    /**
     * ADS por anúncio no período. Fonte principal: campanhas da API
     * (shopee_ad_campanha_dias, a mesma da Estratégia Shopee). Sem gasto
     * nelas no período (homolog, sincronização parada), usa o ADS da coleta
     * diária gravado em shopee_metricas, com o aviso do período.
     */
    private function adsPorItem(Carbon $desde, Carbon $ate): array
    {
        $estrategia = $this->estrategia->gerar($desde, $ate);
        $campanhas = array_merge($estrategia['campanhas'], $estrategia['decididas']);
        $porItem = [];
        foreach ($campanhas as $c) {
            if (! $c['item_id']) {
                continue;
            }
            $a = $porItem[$c['item_id']] ?? ['gasto' => 0.0, 'venda' => 0.0, 'venda_direta' => 0.0, 'pedidos' => 0, 'cliques' => 0, 'impressoes' => 0,
                'ativo' => false, 'meta' => null, 'tipo' => null, 'situacao' => null];
            $a['gasto'] += $c['gasto'];
            $a['venda'] += $c['venda'];
            $a['venda_direta'] += $c['venda_direta'];
            $a['pedidos'] += $c['pedidos'];
            $a['cliques'] += $c['cliques'];
            $a['impressoes'] += $c['impressoes'];
            if ($c['ativa']) {
                $a['ativo'] = true;
                $a['meta'] = $c['meta_configurada'];
                $a['tipo'] = $c['tipo'];
            }
            $a['situacao'] ??= $c['situacao'];
            $porItem[$c['item_id']] = $a;
        }

        $gasto = array_sum(array_column($porItem, 'gasto'));
        if ($gasto > 0) {
            return ['fonte' => ['tipo' => 'api', 'inicio' => $desde->toDateString(), 'fim' => $ate->toDateString(), 'coletado_em' => $estrategia['sincronizado_em']],
                'itens' => $porItem, 'por_dia' => $this->adsPorDia($desde, $ate)];
        }

        $coleta = $this->leitor->porItem('anuncio_ads', $desde, $ate);
        $porItem = [];
        foreach ($coleta['itens'] as $item => $info) {
            $m = $info['m'];
            $porItem[$item] = [
                'gasto' => (float) ($m['gasto_ads'] ?? 0), 'venda' => (float) ($m['venda_ads'] ?? 0), 'venda_direta' => null,
                'pedidos' => (int) ($m['pedidos_ads'] ?? 0), 'cliques' => (int) ($m['cliques_ads'] ?? 0), 'impressoes' => (int) ($m['impressoes_ads'] ?? 0),
                'ativo' => ($m['ads_ativo'] ?? 0) > 0, 'meta' => isset($m['meta_roas']) && $m['meta_roas'] > 0 ? (float) $m['meta_roas'] : null,
                'tipo' => null, 'situacao' => null,
            ];
        }

        return ['fonte' => $coleta['fonte'] ? ['tipo' => 'coleta'] + $coleta['fonte'] : null, 'itens' => $porItem, 'por_dia' => []];
    }

    private function adsPorDia(Carbon $desde, Carbon $ate): array
    {
        return DB::table('shopee_ad_campanha_dias')->whereBetween('data', [$desde->toDateString(), $ate->toDateString()])
            ->groupBy('data')->orderBy('data')
            ->selectRaw('data, SUM(gasto) as gasto, SUM(venda) as venda')->get()
            ->map(fn ($d) => ['data' => substr((string) $d->data, 0, 10), 'gasto' => round((float) $d->gasto, 2), 'venda' => round((float) $d->venda, 2)])
            ->all();
    }

    /** Uma linha por anúncio com tudo: funil, vendas, dinheiro, ADS, ritmo e estoque. */
    private function produtos(array $tela, array $anuncios, array $vendas, array $ads, float $taxaMedia, int $dias): array
    {
        $ids = collect(array_keys($tela))->merge(array_keys($anuncios))->merge(array_keys($ads))
            ->merge(collect($vendas)->pluck('item_id')->filter())
            ->map(fn ($id) => (string) $id)->unique()->values();
        $vendaDoItem = collect($vendas)->filter(fn ($v) => $v['item_id'] !== null)->keyBy('item_id');

        $linhas = [];
        foreach ($ids as $id) {
            $m = $tela[$id]['m'] ?? null;
            $anuncio = $anuncios[$id] ?? null;
            $venda = $vendaDoItem->get($id);
            $ad = $ads[$id] ?? null;
            $eco = $venda['eco'] ?? ($anuncio && $anuncio['preco'] > 0 ? $this->economia->estimar($anuncio['preco'], $anuncio['custo'], $taxaMedia) : null);
            $semCusto = $eco ? (bool) ($eco['sem_custo'] ?? false) : ($anuncio ? $anuncio['custo'] === null : true);
            $linhas[$id] = $this->linhaProduto($id, $m, $tela[$id]['nome'] ?? null, $anuncio, $venda, $ad, $eco, $semCusto, $dias);
        }

        // Produto vendido sem anúncio ligado no KazaKora: entra para a soma bater.
        foreach ($vendas as $chave => $v) {
            if ($v['item_id'] === null) {
                $linhas[$chave] = $this->linhaProduto(null, null, $v['nome'], null, $v, null, $v['eco'], $v['sem_custo'], $dias);
            }
        }

        // Posição no ranking da loja pela receita do período.
        $ranking = collect($linhas)->filter(fn ($l) => $l['receita'] > 0)->sortByDesc('receita')->keys()->values();
        foreach ($ranking as $posicao => $chave) {
            $linhas[$chave]['ranking'] = $posicao + 1;
        }

        uasort($linhas, fn ($a, $b) => [$b['unidades'], $b['visitantes'] ?? 0] <=> [$a['unidades'], $a['visitantes'] ?? 0]);

        return $linhas;
    }

    private function linhaProduto(?string $id, ?array $m, ?string $nomeTela, ?array $anuncio, ?array $venda, ?array $ad, ?array $eco, bool $semCusto, int $dias): array
    {
        $carrinho = $m['add_carrinho_compradores'] ?? null;
        $compraram = $m['compradores'] ?? ($m['pedidos'] ?? null);
        $abandono = $carrinho !== null && $carrinho > 0 && $compraram !== null ? round(max(0, 1 - $compraram / $carrinho) * 100, 1) : null;
        $unidades = (int) ($venda['unidades'] ?? 0);
        $estoque = $anuncio['estoque'] ?? null;
        $vendaDia = $unidades / max(1, $dias);
        $diasEstoque = $estoque !== null && $vendaDia > 0 ? (int) floor($estoque / $vendaDia) : null;
        $custoUnidade = $anuncio['custo'] ?? ($venda && $venda['unidades'] > 0 && ! $venda['sem_custo'] ? $venda['custo'] / $venda['unidades'] : null);
        $lucroUnidade = $eco && ! $semCusto ? $eco['lucro_unidade'] : null;
        $margemPreco = $eco && ! $semCusto && $eco['preco'] > 0 ? $eco['lucro_unidade'] / $eco['preco'] : null;
        $gasto = $ad['gasto'] ?? 0.0;

        return [
            'item_id' => $id,
            'nome' => $anuncio['nome'] ?? $nomeTela ?? ($venda['nome'] ?? "Anúncio {$id}"),
            'sku' => $anuncio['sku'] ?? null,
            'product_id' => $anuncio['product_id'] ?? ($venda['product_id'] ?? null),
            'link_shopee' => $id ? "https://seller.shopee.com.br/portal/product/{$id}" : null,
            'status' => $anuncio['status'] ?? null,
            'tem_tela' => $m !== null,
            // Funil (Tela).
            'impressoes' => isset($m['impressoes']) ? (int) $m['impressoes'] : null,
            'cliques' => isset($m['cliques']) ? (int) $m['cliques'] : null,
            'ctr' => $m['ctr'] ?? null,
            'visitantes' => isset($m['visitantes']) ? (int) $m['visitantes'] : null,
            'rejeicao' => $m['taxa_rejeicao'] ?? null,
            'carrinho' => $carrinho !== null ? (int) $carrinho : null,
            'abandono' => $abandono,
            'conversao' => $m['conversao'] ?? null,
            'pedidos_tela' => isset($m['pedidos']) ? (int) $m['pedidos'] : null,
            // Vendas (KazaKora).
            'pedidos' => (int) ($venda['pedidos'] ?? 0),
            'unidades' => $unidades,
            'receita' => round((float) ($venda['receita'] ?? 0), 2),
            'ticket' => ($venda['pedidos'] ?? 0) > 0 ? round($venda['receita'] / $venda['pedidos'], 2) : null,
            'ranking' => null,
            'lucro' => $venda && ! $venda['sem_custo'] ? $venda['lucro'] : null,
            // Dinheiro.
            'preco' => $anuncio['preco'] ?? ($eco['preco'] ?? null),
            'preco_medio' => $venda ? $eco['preco'] : null,
            'custo' => $custoUnidade !== null ? round($custoUnidade, 2) : null,
            'lucro_unidade' => $lucroUnidade,
            'margem' => $margemPreco !== null ? round($margemPreco * 100, 1) : null,
            'lucro_pct_custo' => $lucroUnidade !== null && $custoUnidade ? round($lucroUnidade / $custoUnidade * 100, 1) : null,
            'preco_minimo' => $eco['preco_minimo'] ?? null,
            'estimado' => (bool) ($eco['estimado'] ?? false),
            'sem_custo' => $semCusto,
            // ADS.
            'ads_ativo' => (bool) ($ad['ativo'] ?? false),
            'ads_tipo' => $ad['tipo'] ?? null,
            'ads_situacao' => $ad['situacao'] ?? null,
            'gasto' => round($gasto, 2),
            'venda_ads' => round((float) ($ad['venda'] ?? 0), 2),
            'pedidos_ads' => (int) ($ad['pedidos'] ?? 0),
            'cliques_ads' => (int) ($ad['cliques'] ?? 0),
            'impressoes_ads' => (int) ($ad['impressoes'] ?? 0),
            'meta_configurada' => $ad['meta'] ?? null,
            'roas' => $gasto > 0 ? round($ad['venda'] / $gasto, 1) : null,
            'roas_empate' => $semCusto ? null : ($eco['roas_empate'] ?? null),
            'roas_meta' => $semCusto ? null : ($eco['roas_meta'] ?? null),
            'resultado_ads' => $gasto > 0 && $margemPreco !== null ? round($ad['venda'] * $margemPreco - $gasto, 2) : null,
            'custo_por_pedido' => $gasto > 0 && ($ad['pedidos'] ?? 0) > 0 ? round($gasto / $ad['pedidos'], 2) : null,
            // Ritmo e estoque.
            'intervalo_horas' => $venda['intervalo_horas'] ?? null,
            'ultima_venda' => $venda['ultima_venda'] ?? null,
            'estoque' => $estoque,
            'dias_estoque' => $diasEstoque,
        ];
    }

    /** Relâmpagos configuradas (shopee_relampago_itens ativas) com o lucro pela régua de 15% do custo. */
    private function relampagos(array $anuncios, float $taxaMedia): array
    {
        if (! Schema::hasTable('shopee_relampago_itens')) {
            return [];
        }
        $custos = Product::query()->whereIn('id', ShopeeRelampagoItem::query()->whereNotNull('product_id')->pluck('product_id'))->pluck('cost_price', 'id');

        return ShopeeRelampagoItem::query()->where('ativo', true)->orderBy('dia')->orderBy('id')->get()
            ->map(function ($r) use ($anuncios, $custos, $taxaMedia) {
                $item = (string) $r->shopee_item_id;
                $custo = $r->product_id && isset($custos[$r->product_id]) ? (float) $custos[$r->product_id] : ($anuncios[$item]['custo'] ?? null);
                $preco = $r->preco !== null ? (float) $r->preco : null;
                $eco = $preco && $custo ? $this->economia->estimar($preco, $custo, $taxaMedia) : null;
                $pct = $eco && $custo > 0 ? round($eco['lucro_unidade'] / $custo * 100, 1) : null;

                return [
                    'item_id' => $item,
                    'nome' => $r->nome ?? ($anuncios[$item]['nome'] ?? "Anúncio {$item}"),
                    'dia' => $r->dia?->toDateString(),
                    'ultimo_dia' => $r->ultimo_dia?->toDateString(),
                    'preco' => $preco,
                    'unidades' => (int) $r->unidades,
                    'custo' => $custo,
                    'lucro_unidade' => $eco['lucro_unidade'] ?? null,
                    'lucro_pct_custo' => $pct,
                    'minimo' => $custo ? round($custo * self::PISO / 100, 2) : null,
                    'dentro_do_piso' => $pct === null ? null : $pct >= self::PISO,
                    'ultimo_resultado' => $r->ultimo_resultado,
                ];
            })->all();
    }

    /**
     * Riscos e oportunidades por anúncio: uma linha por produto, o alerta
     * mais grave em destaque e os outros como selos.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function alertas(array $produtos, array $relampagos, Carbon $desde, Carbon $ate): array
    {
        $dinheiro = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
        $um = fn ($v) => number_format((float) $v, 1, ',', '.');
        $porItem = [];
        $oportunidades = [];
        $periodo = $desde->format('d/m').' a '.$ate->format('d/m');

        foreach ($produtos as $chave => $p) {
            $a = [];
            $estimado = $p['estimado'] ? ' (estimado com a taxa média da Shopee, sem venda no período)' : '';

            // R1, R2, R4: preço e lucro (KazaKora).
            if ($p['sem_custo'] && $p['unidades'] > 0) {
                $a[] = $this->alerta('R4', 'atencao', 'preco', 'Sem custo cadastrado: não dá para saber se este produto dá lucro. Cadastre o custo.',
                    "{$p['unidades']} unidades vendidas no período", 'KazaKora', null, 'produto');
            } elseif ($p['lucro_unidade'] !== null && $p['lucro_unidade'] <= 0 && ($p['unidades'] > 0 || $p['ads_ativo'] || $p['status'] === 'published' || $p['status'] === 'active')) {
                $minimo = $p['preco_minimo'] ? " Para sobrar 15%, o preço precisa ser pelo menos {$dinheiro($p['preco_minimo'])}." : '';
                $a[] = $this->alerta('R1', 'grave', 'preco', "Vende com prejuízo antes do ADS: {$dinheiro($p['lucro_unidade'])} por unidade.{$minimo}",
                    'Preço '.$dinheiro($p['preco_medio'] ?? $p['preco']).' · custo '.$dinheiro($p['custo']).$estimado, 'KazaKora',
                    $p['unidades'] > 0 ? round($p['lucro_unidade'] * $p['unidades'], 2) : null, 'produto');
            } elseif ($p['lucro_unidade'] !== null && $p['margem'] !== null && $p['margem'] < self::PISO && $p['unidades'] > 0) {
                $minimo = $p['preco_minimo'] ? " Preço mínimo para 15%: {$dinheiro($p['preco_minimo'])}." : '';
                $a[] = $this->alerta('R2', 'grave', 'preco', "Abaixo do piso de 15%: sobra {$um($p['margem'])}% do que o cliente pagou.{$minimo}",
                    'Média das vendas do período, '.$dinheiro($p['preco_medio']).' por unidade. O maior cupom da loja ainda não é lido pela API.', 'KazaKora', null, 'produto');
            }

            // R5 a R8: ADS.
            if ($p['gasto'] >= EstrategiaShopeeAds::GASTO_SEM_VENDA && $p['pedidos_ads'] === 0) {
                $a[] = $this->alerta('R5', 'grave', 'ads', "ADS gastou {$dinheiro($p['gasto'])} sem nenhuma venda. Pause ou troque capa e preço antes de continuar.",
                    "{$p['cliques_ads']} cliques no anúncio pago", 'API', -$p['gasto'], 'ads');
            } elseif ($p['gasto'] >= EstrategiaShopeeAds::GASTO_MINIMO && $p['roas'] !== null && $p['roas_empate'] && $p['roas'] < $p['roas_empate']) {
                $a[] = $this->alerta('R6', 'grave', 'ads', "ADS dando prejuízo: ROAS {$um($p['roas'])}, o empate é {$um($p['roas_empate'])}. Cada R$ 1 de anúncio volta menos do que custa.",
                    "Gasto {$dinheiro($p['gasto'])} · venda pelo ADS {$dinheiro($p['venda_ads'])}", 'API', $p['resultado_ads'], 'ads');
            }
            $metaPraticavel = $p['roas_meta'] && $p['roas_meta'] <= 25 ? $p['roas_meta'] : null;
            if ($p['ads_ativo'] && $metaPraticavel && $p['meta_configurada'] > 0 && $p['meta_configurada'] < $metaPraticavel) {
                $a[] = $this->alerta('R7', 'atencao', 'ads', "Meta de ROAS {$um($p['meta_configurada'])} abaixo do mínimo para 15% de lucro ({$um($metaPraticavel)}).",
                    "Empate {$um($p['roas_empate'])}", 'API', null, 'ads');
            }
            if ($p['ads_ativo'] && ! ($p['meta_configurada'] > 0) && in_array($p['ads_tipo'], ['automatica', 'meta_roas'], true) && $p['roas_empate']) {
                $a[] = $this->alerta('R8', 'atencao', 'ads', "ADS sem meta de ROAS: a Shopee gasta sem olhar o retorno. Empate {$um($p['roas_empate'])}.",
                    'Lance automático sem meta', 'API', null, 'ads');
            }

            // R9 a R11: funil (Tela).
            if ($p['ctr'] !== null && $p['impressoes'] >= self::IMPRESSOES_MINIMAS && $p['ctr'] < self::CTR_MINIMO) {
                $a[] = $this->alerta('R9', 'atencao', 'anuncio', "Capa fraca: só {$um($p['ctr'])}% de quem vê clica (bom é 2% ou mais). Troque a foto de capa.",
                    number_format($p['impressoes'], 0, ',', '.').' impressões', 'Tela', null, 'shopee');
            }
            if ($p['conversao'] !== null && $p['visitantes'] >= self::VISITANTES_MINIMOS && $p['conversao'] < self::CONVERSAO_MINIMA) {
                $a[] = $this->alerta('R10', 'atencao', 'funil', "Muita visita e pouca venda: {$um($p['conversao'])}% de {$p['visitantes']} visitantes. Revise preço, fotos, descrição e frete.",
                    "{$p['visitantes']} visitantes", 'Tela', null, 'produto');
            }
            if ($p['abandono'] !== null && $p['carrinho'] >= self::CARRINHO_MINIMO && $p['abandono'] >= self::ABANDONO_MAXIMO) {
                $a[] = $this->alerta('R11', 'atencao', 'funil', "{$um($p['abandono'])}% de quem pôs no carrinho desistiu. Costuma ser preço final ou frete.",
                    "{$p['carrinho']} pessoas no carrinho", 'Tela', null, 'produto');
            }

            // R16: estoque.
            if ($p['dias_estoque'] !== null && $p['dias_estoque'] < self::DIAS_ESTOQUE_ATENCAO) {
                $a[] = $this->alerta('R16', 'atencao', 'estoque', "Estoque para {$p['dias_estoque']} dias no ritmo atual.",
                    "{$p['estoque']} em estoque · {$p['unidades']} vendidas no período", 'KazaKora', null, 'produto');
            }

            if ($a !== []) {
                $porItem[$chave] = ['produto' => $p, 'alertas' => $a];
            }

            // O1 (régua do Cauê): sem ADS, converte bem e a margem aguenta ADS.
            if (! $p['ads_ativo'] && $p['conversao'] !== null && $p['visitantes'] >= 50 && $metaPraticavel && ($p['lucro_unidade'] ?? 0) > 0
                && (($p['conversao'] >= 2 && $p['roas_empate'] <= 8) || ($p['conversao'] >= 1.65 && $p['roas_empate'] <= 6))) {
                $oportunidades[] = $this->linhaAlerta($p, [$this->alerta('O1', 'oportunidade', 'ads',
                    "Converte {$um($p['conversao'])}% e a margem aguenta ADS. Candidato a ADS com meta de ROAS {$um($metaPraticavel)}.",
                    "{$p['visitantes']} visitantes · empate {$um($p['roas_empate'])}", 'Tela', null, 'ads')]);
            }
        }

        // R3: Relâmpago abaixo de 15% do custo (regra do Lira).
        foreach ($relampagos as $r) {
            if ($r['dentro_do_piso'] === false) {
                $chave = $r['item_id'];
                $produto = $produtos[$chave] ?? ['item_id' => $r['item_id'], 'nome' => $r['nome'], 'link_shopee' => "https://seller.shopee.com.br/portal/product/{$r['item_id']}",
                    'product_id' => null, 'visitantes' => null];
                $porItem[$chave] ??= ['produto' => $produto, 'alertas' => []];
                $porItem[$chave]['alertas'][] = $this->alerta('R3', 'grave', 'promocao',
                    "Relâmpago abaixo do piso: lucro de {$dinheiro($r['lucro_unidade'])} ({$um($r['lucro_pct_custo'])}% do custo). O mínimo é 15% do custo, {$dinheiro($r['minimo'])}.",
                    'Preço da Relâmpago '.$dinheiro($r['preco']).($r['dia'] ? ' · dia '.Carbon::parse($r['dia'])->format('d/m') : ''), 'KazaKora', null, 'produto');
            }
        }

        // R14, R20, R23, R24: status, nota, fotos e vídeo vêm do diagnóstico
        // guardado do Cauê (a tela não chama a API da Shopee).
        $diag = $this->diagnostico();
        $quando = isset($diag['gerado_em']) ? Carbon::parse($diag['gerado_em'])->timezone(config('app.timezone'))->format('d/m H:i') : null;
        $mapa = ['status' => ['R14', 'anuncio', 'shopee'], 'avaliacao' => ['R20', 'avaliacao', 'shopee'], 'fotos' => ['R23', 'anuncio', 'shopee'], 'video' => ['R24', 'anuncio', 'shopee']];
        $gravidade = ['P0' => 'grave', 'P1' => 'atencao', 'P2' => 'melhorar'];
        foreach ($diag['alertas'] ?? [] as $d) {
            $tipo = $d['tipo'] ?? '';
            $item = (string) ($d['produto']['item_id'] ?? '');
            if (! isset($mapa[$tipo]) || $item === '' || ! isset($gravidade[$d['prioridade'] ?? ''])) {
                continue;
            }
            [$codigo, $tema, $destino] = $mapa[$tipo];
            $produto = $produtos[$item] ?? ['item_id' => $item, 'nome' => $d['produto']['nome'] ?? "Anúncio {$item}", 'link_shopee' => "https://seller.shopee.com.br/portal/product/{$item}",
                'product_id' => null, 'visitantes' => null];
            $porItem[$item] ??= ['produto' => $produto, 'alertas' => []];
            $porItem[$item]['alertas'][] = $this->alerta($codigo, $gravidade[$d['prioridade']], $tema, (string) $d['texto'],
                $quando ? "Diagnóstico do Cauê de {$quando}" : 'Diagnóstico do Cauê', 'API', null, $destino);
        }

        $riscos = array_map(fn ($g) => $this->linhaAlerta($g['produto'], $g['alertas']), array_values($porItem));
        usort($riscos, fn ($x, $y) => [self::GRAVIDADE[$x['gravidade']], $x['valor'] ?? 0, -($x['visitantes'] ?? 0)]
            <=> [self::GRAVIDADE[$y['gravidade']], $y['valor'] ?? 0, -($y['visitantes'] ?? 0)]);
        usort($oportunidades, fn ($x, $y) => ($y['visitantes'] ?? 0) <=> ($x['visitantes'] ?? 0));

        return [$riscos, $oportunidades];
    }

    private function alerta(string $codigo, string $gravidade, string $tema, string $texto, ?string $numero, string $fonte, ?float $valor, string $destino): array
    {
        return compact('codigo', 'gravidade', 'tema', 'texto', 'numero', 'fonte', 'valor', 'destino');
    }

    private function linhaAlerta(array $p, array $alertas): array
    {
        usort($alertas, fn ($x, $y) => [self::GRAVIDADE[$x['gravidade']] ?? 3, $x['valor'] ?? 0] <=> [self::GRAVIDADE[$y['gravidade']] ?? 3, $y['valor'] ?? 0]);
        $valores = array_filter(array_column($alertas, 'valor'), fn ($v) => $v !== null && $v < 0);

        return [
            'item_id' => $p['item_id'] ?? null,
            'nome' => $p['nome'],
            'link_shopee' => $p['link_shopee'] ?? null,
            'product_id' => $p['product_id'] ?? null,
            'visitantes' => $p['visitantes'] ?? null,
            'gravidade' => $alertas[0]['gravidade'],
            'valor' => $valores ? round(array_sum($valores), 2) : null,
            'alertas' => $alertas,
        ];
    }

    /** Diagnóstico guardado do Cauê (comando marketplace:diagnostico, só leitura da API). */
    private function diagnostico(): array
    {
        if ($this->diagnostico === null) {
            $dados = Schema::hasTable('diagnosticos_canal') ? DB::table('diagnosticos_canal')->where('canal', self::CANAL)->value('dados') : null;
            $this->diagnostico = $dados ? (json_decode((string) $dados, true) ?: []) : [];
        }

        return $this->diagnostico;
    }

    /**
     * Funil da loja somando os anúncios: contagens somam; CTR = cliques ÷
     * impressões; conversão e rejeição = média ponderada pelos visitantes.
     */
    private function funilLoja(array $itens): ?array
    {
        if ($itens === []) {
            return null;
        }
        $somar = fn (string $k) => collect($itens)->filter(fn ($i) => isset($i['m'][$k]))->isEmpty()
            ? null : (float) collect($itens)->sum(fn ($i) => $i['m'][$k] ?? 0);
        $ponderada = function (string $k) use ($itens) {
            $num = 0.0;
            $den = 0.0;
            foreach ($itens as $i) {
                if (isset($i['m'][$k], $i['m']['visitantes'])) {
                    $num += $i['m'][$k] * $i['m']['visitantes'];
                    $den += $i['m']['visitantes'];
                }
            }

            return $den > 0 ? round($num / $den, 2) : null;
        };

        $f = [];
        foreach (['impressoes', 'cliques', 'visitantes', 'visualizacoes', 'add_carrinho', 'add_carrinho_compradores', 'pedidos', 'compradores', 'pedidos_pagos', 'vendas', 'vendas_pagas'] as $k) {
            $f[$k] = $somar($k);
        }
        $f['ctr'] = $f['impressoes'] ? round($f['cliques'] / $f['impressoes'] * 100, 2) : null;
        $f['conversao'] = $ponderada('conversao');
        $f['taxa_rejeicao'] = $ponderada('taxa_rejeicao');
        $f['taxa_carrinho'] = $f['visitantes'] && $f['add_carrinho_compradores'] !== null ? round($f['add_carrinho_compradores'] / $f['visitantes'] * 100, 2) : null;
        $compraram = $f['compradores'] ?? $f['pedidos'];
        $f['abandono'] = $f['add_carrinho_compradores'] && $compraram !== null ? round(max(0, 1 - $compraram / $f['add_carrinho_compradores']) * 100, 1) : null;
        $f['taxa_pagamento'] = $f['pedidos'] && $f['pedidos_pagos'] !== null ? round($f['pedidos_pagos'] / $f['pedidos'] * 100, 1) : null;
        $f['anuncios'] = count($itens);

        return $f;
    }

    private function resumoAds(array $ads, array $produtos, int $dias): array
    {
        $itens = $ads['itens'];
        $gasto = array_sum(array_column($itens, 'gasto'));
        $venda = array_sum(array_column($itens, 'venda'));
        $pedidos = array_sum(array_column($itens, 'pedidos'));
        $cliques = array_sum(array_column($itens, 'cliques'));
        $impressoes = array_sum(array_column($itens, 'impressoes'));
        $metas = [];
        foreach ($produtos as $p) {
            if ($p['roas_meta'] && $p['roas_meta'] <= 25 && $p['venda_ads'] > 0) {
                $metas[] = [$p['roas_meta'], $p['venda_ads']];
            }
        }
        $linhas = array_values(array_filter($produtos, fn ($p) => $p['gasto'] > 0));
        usort($linhas, fn ($a, $b) => $b['gasto'] <=> $a['gasto']);

        return [
            'fonte' => $ads['fonte'],
            'kpis' => [
                'gasto' => round($gasto, 2),
                'gasto_dia' => round($gasto / max(1, $dias), 2),
                'venda' => round($venda, 2),
                'venda_direta' => ($ads['fonte']['tipo'] ?? null) === 'api' ? round((float) array_sum(array_column($itens, 'venda_direta')), 2) : null,
                'pedidos' => $pedidos,
                'cliques' => $cliques,
                'impressoes' => $impressoes,
                'roas' => $gasto > 0 ? round($venda / $gasto, 1) : null,
                'acos' => $venda > 0 ? round($gasto / $venda * 100, 1) : null,
                'custo_por_pedido' => $pedidos > 0 ? round($gasto / $pedidos, 2) : null,
                'ctr' => $impressoes > 0 ? round($cliques / $impressoes * 100, 2) : null,
                'cpc' => $cliques > 0 ? round($gasto / $cliques, 2) : null,
                'conversao' => $cliques > 0 ? round($pedidos / $cliques * 100, 2) : null,
                'roas_meta_loja' => DiagnosticoShopee::mediaPonderada($metas),
                'ativos' => count(array_filter($itens, fn ($a) => $a['ativo'])),
            ],
            'anuncios' => array_slice($linhas, 0, 20),
            'total_anuncios' => count($linhas),
            'por_dia' => $ads['por_dia'],
        ];
    }

    /**
     * Pedidos da Shopee por dia da semana × hora (fuso da loja) e o tempo
     * entre um pedido e o seguinte; visitas por hora da coleta da tela.
     */
    private function horarios(Carbon $desde, Carbon $fim, array $horas): array
    {
        $momentos = Order::query()->vendaEfetiva()->where('origin', Order::ORIGIN_SHOPEE)
            ->whereIn('status', ContributionMargin::REVENUE_STATUSES)
            ->where('created_at', '>=', $desde)->where('created_at', '<', $fim)
            ->orderBy('created_at')->pluck('created_at');

        // 0 = domingo ... 6 = sábado (Carbon dayOfWeek).
        $mapa = array_fill(0, 7, array_fill(0, 24, 0));
        $porHora = array_fill(0, 24, 0);
        $intervalos = [];
        $anterior = null;
        foreach ($momentos as $m) {
            $c = Carbon::parse($m);
            $mapa[$c->dayOfWeek][$c->hour]++;
            $porHora[$c->hour]++;
            if ($anterior) {
                $intervalos[] = $anterior->diffInMinutes($c);
            }
            $anterior = $c;
        }
        sort($intervalos);
        $n = count($intervalos);

        // Faixa de 3 horas com mais pedidos (texto "Pico").
        $pico = null;
        if ($momentos->count() > 0) {
            $melhor = -1;
            for ($h = 0; $h < 24; $h++) {
                $soma = $porHora[$h] + $porHora[($h + 1) % 24] + $porHora[($h + 2) % 24];
                if ($soma > $melhor) {
                    $melhor = $soma;
                    $pico = ['de' => $h, 'ate' => ($h + 3) % 24, 'pedidos' => $soma, 'pct' => round($soma / $momentos->count() * 100, 1)];
                }
            }
        }

        $visitas = null;
        if ($horas['dias_coletados'] > 0) {
            $visitas = [];
            for ($h = 0; $h < 24; $h++) {
                $visitas[$h] = isset($horas['por_hora'][$h]['visitantes']) ? (int) $horas['por_hora'][$h]['visitantes'] : null;
            }
        }

        return [
            'pedidos' => $momentos->count(),
            'mapa' => $mapa,
            'por_hora' => $porHora,
            'pico' => $pico,
            'intervalo_medio_min' => $n ? round(array_sum($intervalos) / $n) : null,
            'intervalo_mediana_min' => $n ? ($n % 2 ? $intervalos[intdiv($n, 2)] : round(($intervalos[$n / 2 - 1] + $intervalos[$n / 2]) / 2)) : null,
            'visitas_por_hora' => $visitas,
            'pedidos_por_hora_tela' => $horas['dias_coletados'] > 0
                ? array_map(fn ($h) => isset($horas['por_hora'][$h]['pedidos']) ? (int) $horas['por_hora'][$h]['pedidos'] : null, range(0, 23)) : null,
            'visitas_dias' => $horas['dias_coletados'],
            'visitas_inicio' => $horas['inicio'],
            'visitas_fim' => $horas['fim'],
        ];
    }

    private function avaliacoes(Carbon $desde, Carbon $fim): array
    {
        $base = fn () => DB::table('reviews')->where('reviews.channel', self::CANAL)
            ->where('reviews.created_at', '>=', $desde)->where('reviews.created_at', '<', $fim);
        $ruins = $base()->where('reviews.rating', '<=', 2)->leftJoin('products', 'products.id', '=', 'reviews.product_id')
            ->orderByDesc('reviews.created_at')->limit(5)
            ->get(['reviews.id', 'reviews.rating', 'reviews.comment', 'reviews.created_at', 'products.name as produto']);

        return [
            'total' => $base()->count(),
            'media' => ($m = $base()->avg('rating')) !== null ? round((float) $m, 2) : null,
            'ruins' => $base()->where('rating', '<=', 2)->count(),
            'ultimas_ruins' => $ruins->map(fn ($r) => [
                'id' => $r->id, 'nota' => (int) $r->rating, 'produto' => $r->produto,
                'texto' => $r->comment ? mb_strimwidth((string) $r->comment, 0, 160, '…') : null,
                'data' => substr((string) $r->created_at, 0, 10),
            ])->all(),
        ];
    }

    private function devolucoes(Carbon $desde, Carbon $fim, int $pedidos): array
    {
        if (! Schema::hasTable('marketplace_returns')) {
            return ['total' => 0, 'pct' => null, 'por_motivo' => []];
        }
        $base = fn () => DB::table('marketplace_returns')->where('channel', self::CANAL)
            ->whereRaw('COALESCE(opened_at, created_at) >= ?', [$desde])->whereRaw('COALESCE(opened_at, created_at) < ?', [$fim]);
        $total = $base()->count();

        return [
            'total' => $total,
            'pct' => $pedidos > 0 ? round($total / $pedidos * 100, 1) : null,
            'por_motivo' => $base()->selectRaw("COALESCE(reason_label, reason_code, 'Sem motivo informado') as motivo, COUNT(*) as qtd")
                ->groupBy('motivo')->orderByDesc('qtd')->get()->map(fn ($r) => ['motivo' => $r->motivo, 'qtd' => (int) $r->qtd])->all(),
        ];
    }
}
